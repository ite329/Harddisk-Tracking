<?php

require_once __DIR__ . '/../../../includes/auth.php';
$pageTitle = 'ผู้ใช้งานออนไลน์';

require_once __DIR__ . '/../../../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Bangkok');

if (!function_exists('onlineE')) {
    function onlineE($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('onlineIsSuperAdmin')) {
    function onlineIsSuperAdmin(): bool
    {
        return function_exists('can') && can('admin.online_users');
    }
}



if (!function_exists('onlineTableExists')) {
    function onlineTableExists(PDO $pdo, string $tableName): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
        $stmt->execute([':table_name' => $tableName]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('onlineColumnExists')) {
    function onlineColumnExists(PDO $pdo, string $tableName, string $columnName): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name");
        $stmt->execute([':table_name' => $tableName, ':column_name' => $columnName]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('onlinePageName')) {
    function onlinePageName(string $url): string
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $path = str_replace('\\', '/', $path);
        $pageMap = [
            '/modules/assets/' => 'ค้นหาข้อมูลทรัพย์สิน',
            '/modules/requests/create.php' => 'บันทึกคำขอส่ง Harddisk',
            '/modules/requests/matched.php' => 'รอยืนยันจัดส่ง',
            '/modules/requests/index.php' => 'รายการเบิก Harddisk',
            '/modules/shipments/' => 'ประวัติการจัดส่ง Harddisk',
            '/modules/inventory/' => 'คลัง Harddisk',
            '/modules/claim_returns/' => 'รับคืน / ส่งเคลม',
            '/modules/branch_labels/' => 'ค้นหาสาขาและพิมพ์ที่อยู่สาขา',
            '/modules/delete_computers/' => 'ลบชื่อเครื่อง Join Domain',
            '/modules/keyboard_mouse/' => 'บันทึกข้อมูล Keyboard & Mouse',
            '/modules/servers/' => 'ข้อมูล Server',
            '/modules/it_systems/' => 'ข้อมูลระบบไอทีสารสนเทศ',
            '/modules/license_software/' => 'ข้อมูล License Software',
            '/modules/notebooks/' => 'ข้อมูล License Notebook',
            '/modules/wcs_repair_quotes/' => 'ใบเสนอราคาซ่อม WCS',
            '/modules/delivery_logs/' => 'บันทึกรายการส่งของ',
            '/modules/drum_requests/' => 'บันทึกการเบิก Drum',
            '/modules/admin/branch_import/' => 'อัปเดตข้อมูลสาขา',
            '/modules/admin/asset_import/' => 'อัปโหลดข้อมูลทรัพย์สิน',
            '/modules/admin/users/' => 'จัดการข้อมูล User',
            '/modules/admin/permissions/' => 'จัดการสิทธิ์ส่วนกลาง',
            '/modules/admin/online_users/' => 'ผู้ใช้งานออนไลน์',
            '/public/login.php' => 'เข้าสู่ระบบ',
        ];
        foreach ($pageMap as $keyword => $pageName) {
            if (strpos($path, $keyword) !== false) {
                return $pageName;
            }
        }
        return $path !== '' ? basename($path) : '-';
    }
}

if (!function_exists('onlineBuildCentralRoleMap')) {
    function onlineBuildCentralRoleMap(PDO $pdo, array $rows): array
    {
        if (!onlineTableExists($pdo, 'users') || !onlineTableExists($pdo, 'user_roles') || !onlineTableExists($pdo, 'roles')) {
            return [];
        }

        $employeeCodes = [];
        $usernames = [];
        foreach ($rows as $row) {
            $employeeCode = trim((string)($row['employee_code'] ?? ''));
            $username = trim((string)($row['username'] ?? ''));
            if ($employeeCode !== '') $employeeCodes[] = $employeeCode;
            if ($username !== '') $usernames[] = $username;
        }
        $employeeCodes = array_values(array_unique($employeeCodes));
        $usernames = array_values(array_unique($usernames));

        $conditions = [];
        $params = [];
        if ($employeeCodes && onlineColumnExists($pdo, 'users', 'employee_code')) {
            $holders = [];
            foreach ($employeeCodes as $i => $value) {
                $key = ':emp_' . $i;
                $holders[] = $key;
                $params[$key] = $value;
            }
            $conditions[] = 'u.employee_code IN (' . implode(',', $holders) . ')';
        }
        if ($usernames && onlineColumnExists($pdo, 'users', 'username')) {
            $holders = [];
            foreach ($usernames as $i => $value) {
                $key = ':username_' . $i;
                $holders[] = $key;
                $params[$key] = $value;
            }
            $conditions[] = 'u.username IN (' . implode(',', $holders) . ')';
        }
        if (!$conditions || !onlineColumnExists($pdo, 'users', 'id')) {
            return [];
        }

        $selectUsername = onlineColumnExists($pdo, 'users', 'username') ? 'u.username' : "'' AS username";
        $selectEmployee = onlineColumnExists($pdo, 'users', 'employee_code') ? 'u.employee_code' : "'' AS employee_code";
        $sql = "SELECT {$selectEmployee}, {$selectUsername}, GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.role_name SEPARATOR ', ') AS role_names
                FROM users u
                LEFT JOIN user_roles ur ON CAST(ur.user_key AS UNSIGNED) = u.id
                LEFT JOIN roles r ON r.id = ur.role_id AND r.is_active = 1
                WHERE " . implode(' OR ', $conditions) . "
                GROUP BY u.id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $user) {
            $roleNames = trim((string)($user['role_names'] ?? ''));
            if ($roleNames === '') $roleNames = 'ยังไม่มี Role';
            $employeeCode = trim((string)($user['employee_code'] ?? ''));
            $username = trim((string)($user['username'] ?? ''));
            if ($employeeCode !== '') $map['employee:' . $employeeCode] = $roleNames;
            if ($username !== '') $map['username:' . $username] = $roleNames;
        }
        return $map;
    }
}

if (!function_exists('onlineEnsureTable')) {
    function onlineEnsureTable(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_online_users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(128) NOT NULL,
            employee_code VARCHAR(50) DEFAULT NULL,
            username VARCHAR(120) DEFAULT NULL,
            full_name VARCHAR(255) DEFAULT NULL,
            role VARCHAR(80) DEFAULT NULL,
            ip_address VARCHAR(80) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            current_url VARCHAR(500) DEFAULT NULL,
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_admin_online_users_session (session_id),
            KEY idx_admin_online_users_last_seen (last_seen_at),
            KEY idx_admin_online_users_employee (employee_code),
            KEY idx_admin_online_users_role (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!onlineIsSuperAdmin()) {
    http_response_code(403);
    echo '<!doctype html><html lang="th"><head><meta charset="utf-8"><title>403 Forbidden</title><link href="../../../assets/css/bootstrap.min.css" rel="stylesheet"></head><body class="p-4"><div class="alert alert-danger">ไม่มีสิทธิ์เข้าถึงหน้านี้ เฉพาะ Super Admin เท่านั้น</div></body></html>';
    exit;
}

$errorMessage = '';
$rows = [];
$onlineCount = 0;
$idleCount = 0;
$todayCount = 0;
$centralRoleMap = [];
$keyword = trim((string)($_GET['keyword'] ?? ''));

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('ไม่พบการเชื่อมต่อฐานข้อมูล');
    }

    onlineEnsureTable($pdo);

    $pdo->exec("DELETE FROM admin_online_users WHERE last_seen_at < (NOW() - INTERVAL 1 DAY)");

    $where = ['last_seen_at >= (NOW() - INTERVAL 1 DAY)'];
    $params = [];
    if ($keyword !== '') {
        $where[] = '(employee_code LIKE :keyword OR username LIKE :keyword OR full_name LIKE :keyword OR role LIKE :keyword OR ip_address LIKE :keyword OR current_url LIKE :keyword)';
        $params[':keyword'] = '%' . $keyword . '%';
    }

    $sql = "SELECT *
        FROM admin_online_users
        WHERE " . implode(' AND ', $where) . "
        ORDER BY last_seen_at DESC
        LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $centralRoleMap = onlineBuildCentralRoleMap($pdo, $rows);

    $onlineCount = (int)$pdo->query("SELECT COUNT(*) FROM admin_online_users WHERE last_seen_at >= (NOW() - INTERVAL 5 MINUTE)")->fetchColumn();
    $idleCount = (int)$pdo->query("SELECT COUNT(*) FROM admin_online_users WHERE last_seen_at < (NOW() - INTERVAL 5 MINUTE) AND last_seen_at >= (NOW() - INTERVAL 30 MINUTE)")->fetchColumn();
    $todayCount = (int)$pdo->query("SELECT COUNT(*) FROM admin_online_users WHERE DATE(last_seen_at) = CURDATE()")->fetchColumn();
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

require_once __DIR__ . '/../../../includes/header.php';

require_login();
require_permission('admin.online_users');

?>
<style>
    .online-kpi-card {
        border: 1px solid rgba(15, 23, 42, .06);
        border-radius: 18px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, .06);
        background: #fff;
        padding: 18px;
        height: 100%;
    }
    .online-kpi-label {
        color: #64748b;
        font-size: .82rem;
        font-weight: 800;
    }
    .online-kpi-value {
        color: #0f172a;
        font-size: 1.85rem;
        font-weight: 900;
        line-height: 1;
        margin-top: 8px;
    }
    .online-dot {
        width: 10px;
        height: 10px;
        border-radius: 999px;
        display: inline-block;
        margin-right: 6px;
    }
    .online-dot-live { background: #22c55e; box-shadow: 0 0 0 4px rgba(34, 197, 94, .12); }
    .online-dot-idle { background: #f59e0b; box-shadow: 0 0 0 4px rgba(245, 158, 11, .12); }
    .online-table th, .online-table td {
        vertical-align: middle;
        font-size: .86rem;
    }
    .online-url {
        max-width: 320px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 fw-bold mb-1">ผู้ใช้งานออนไลน์</h1>
        <div class="text-muted small">แสดง User ที่ยังใช้งานระบบอยู่ โดยอ้างอิงจาก Session และเวลาเข้าใช้งานล่าสุด</div>
    </div>
    <form method="get" class="d-flex gap-2">
        <input type="text" name="keyword" class="form-control" value="<?php echo onlineE($keyword); ?>" placeholder="ค้นหา User / IP / หน้าใช้งาน">
        <button class="btn btn-primary" type="submit">ค้นหา</button>
        <?php if ($keyword !== ''): ?>
            <a class="btn btn-outline-secondary" href="index.php">ล้าง</a>
        <?php endif; ?>
    </form>
</div>

<?php if ($errorMessage !== ''): ?>
    <div class="alert alert-danger">โหลดข้อมูลไม่สำเร็จ: <?php echo onlineE($errorMessage); ?></div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="online-kpi-card">
            <div class="online-kpi-label">ออนไลน์ตอนนี้</div>
            <div class="online-kpi-value text-success"><?php echo number_format($onlineCount); ?></div>
            <div class="text-muted small mt-2">อัปเดตภายใน 5 นาทีล่าสุด</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="online-kpi-card">
            <div class="online-kpi-label">พักการใช้งาน</div>
            <div class="online-kpi-value text-warning"><?php echo number_format($idleCount); ?></div>
            <div class="text-muted small mt-2">ล่าสุด 5-30 นาที</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="online-kpi-card">
            <div class="online-kpi-label">เข้าใช้งานวันนี้</div>
            <div class="online-kpi-value text-primary"><?php echo number_format($todayCount); ?></div>
            <div class="text-muted small mt-2">นับจากรายการ Session วันนี้</div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="fw-bold">รายการ User ที่ใช้งานระบบ</div>
        <span class="badge rounded-pill text-bg-light">แสดงสูงสุด 6 รายการ</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 online-table">
                <thead class="table-light">
                    <tr>
                        <th style="width:70px;">สถานะ</th>
                        <th>ชื่อผู้ใช้งาน</th>
                        <th style="width:110px;">รหัสพนักงาน</th>
                        <th style="width:120px;">Role</th>
                        <th style="width:130px;">IP Address</th>
                        <th>หน้าที่ใช้งานล่าสุด</th>
                        <th style="width:150px;">เข้าครั้งแรก</th>
                        <th style="width:150px;">ล่าสุด</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">ไม่พบข้อมูลผู้ใช้งานออนไลน์</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                                $lastSeen = strtotime((string)($row['last_seen_at'] ?? ''));
                                $isOnline = $lastSeen !== false && $lastSeen >= (time() - 300);
                            ?>
                            <tr>
                                <td>
                                    <?php if ($isOnline): ?>
                                        <span class="badge rounded-pill text-bg-success"><span class="online-dot online-dot-live"></span>Online</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill text-bg-warning"><span class="online-dot online-dot-idle"></span>Idle</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold"><?php echo onlineE($row['full_name'] ?: $row['username'] ?: '-'); ?></div>
                                    <div class="text-muted small">Session: <?php echo onlineE(substr((string)$row['session_id'], 0, 12)); ?>...</div>
                                </td>
                                <td><?php echo onlineE($row['employee_code'] ?: '-'); ?></td>
                                <td><?php
                                    $employeeCode = trim((string)($row['employee_code'] ?? ''));
                                    $username = trim((string)($row['username'] ?? ''));
                                    $centralRole = $centralRoleMap['employee:' . $employeeCode] ?? $centralRoleMap['username:' . $username] ?? 'ยังไม่มี Role';
                                    echo onlineE($centralRole);
                                ?></td>
                                <td><?php echo onlineE($row['ip_address'] ?: '-'); ?></td>
                                <td><div class="online-url"><?php echo onlineE(onlinePageName((string)($row['current_url'] ?? ''))); ?></div></td>
                                <td><?php echo onlineE($row['first_seen_at'] ?: '-'); ?></td>
                                <td><?php echo onlineE($row['last_seen_at'] ?: '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="alert alert-info border-0 shadow-sm mt-3 mb-0">
    ระบบจะถือว่า User <strong>Online</strong> เมื่อมีการเปิดหน้าเว็บภายใน 5 นาทีล่าสุด และจะเก็บประวัติ Session ไว้ 1 วัน
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
