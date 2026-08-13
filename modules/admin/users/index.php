<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('userAdminEscape')) {
    function userAdminEscape($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('userAdminCanAccess')) {
    function userAdminCanAccess(): bool
    {
        return function_exists('can') ? can('user.manage') : false;
    }
}

if (!userAdminCanAccess()) {
    http_response_code(403);
    exit('คุณไม่มีสิทธิ์เข้าถึงหน้าจัดการข้อมูล User');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function userAdminColumns(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT COLUMN_NAME, DATA_TYPE, COLUMN_KEY, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' ORDER BY ORDINAL_POSITION");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($rows as $row) $result[$row['COLUMN_NAME']] = $row;
    return $result;
}

function userAdminPick(array $columns, array $candidates): ?string
{
    foreach ($candidates as $name) if (isset($columns[$name])) return $name;
    return null;
}

function userAdminBoolValue($value): int
{
    return in_array(strtolower(trim((string)$value)), ['1','true','yes','y','on','active','enabled'], true) ? 1 : 0;
}

$columns = userAdminColumns($pdo);
if (!$columns) {
    exit('ไม่พบตาราง harddisk_db.users หรือไม่มีสิทธิ์อ่านโครงสร้างตาราง');
}

$idCol = userAdminPick($columns, ['id','user_id','uid']);
$usernameCol = userAdminPick($columns, ['username','user_name','login_name','login','employee_code','emp_code']);
$passwordCol = userAdminPick($columns, ['password','password_hash','passwd','user_password']);
$employeeCol = userAdminPick($columns, ['employee_code','emp_code','employee_id','staff_code']);
$fullNameCol = userAdminPick($columns, ['full_name','name','display_name','user_fullname']);
$firstNameCol = userAdminPick($columns, ['first_name','firstname']);
$lastNameCol = userAdminPick($columns, ['last_name','lastname','surname']);
$emailCol = userAdminPick($columns, ['email','email_address']);
$roleCol = userAdminPick($columns, ['role','user_role','role_name','level']);
$statusCol = userAdminPick($columns, ['status','user_status','is_active','active','enabled']);
$createdCol = userAdminPick($columns, ['created_at','created_date','date_created']);
$updatedCol = userAdminPick($columns, ['updated_at','updated_date','date_updated']);
$lastLoginCol = userAdminPick($columns, ['last_login_at','last_login','login_at']);

$permissionCandidates = [
    'is_admin' => 'ผู้ดูแลระบบ',
    'is_super_admin' => 'Super Admin',
    'can_manage_users' => 'จัดการ User',
    'can_manage_requests' => 'จัดการคำขอ HDD',
    'can_manage_inventory' => 'จัดการคลัง HDD',
    'can_manage_shipments' => 'จัดการจัดส่ง',
    'can_manage_assets' => 'จัดการทรัพย์สิน',
    'can_view_reports' => 'ดูรายงาน',
];
$permissionColumns = [];
foreach ($permissionCandidates as $col => $label) if (isset($columns[$col])) $permissionColumns[$col] = $label;

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('CSRF Token ไม่ถูกต้อง กรุณารีเฟรชหน้า');
        }
        $action = trim((string)($_POST['action'] ?? ''));
        $recordId = trim((string)($_POST['record_id'] ?? ''));

        if ($action === 'delete') {
            if (!$idCol || $recordId === '') throw new RuntimeException('ไม่พบรหัส User');
            $pdo->beginTransaction();
            try {
                if (function_exists('permission_tables_ready') && permission_tables_ready($pdo)) {
                    try {
                        $pdo->prepare('DELETE FROM user_roles WHERE user_key = :user_key')->execute([':user_key' => $recordId]);
                    } catch (Throwable $cleanupError) {
                        // ไม่ให้ข้อมูล Role เก่าขัดขวางการลบบัญชี User
                    }
                    try {
                        $pdo->prepare('DELETE FROM user_permissions WHERE user_key = :user_key')->execute([':user_key' => $recordId]);
                    } catch (Throwable $cleanupError) {
                        // รองรับฐานข้อมูลที่ยังไม่มีสิทธิ์รายบุคคลหรือใช้โครงสร้างเดิม
                    }
                }
                $stmt = $pdo->prepare("DELETE FROM `users` WHERE `{$idCol}` = :id LIMIT 1");
                $stmt->execute([':id' => $recordId]);
                $pdo->commit();
            } catch (Throwable $deleteError) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $deleteError;
            }
            $message = 'ลบข้อมูล User เรียบร้อยแล้ว';
        } elseif ($action === 'toggle_status') {
            if (!$idCol || !$statusCol || $recordId === '') throw new RuntimeException('ตารางไม่มีคอลัมน์สถานะที่รองรับ');
            $current = trim((string)($_POST['current_status'] ?? ''));
            $meta = $columns[$statusCol];
            if (in_array($meta['DATA_TYPE'], ['tinyint','int','smallint','bigint','bit'], true)) {
                $newValue = userAdminBoolValue($current) ? 0 : 1;
            } else {
                $newValue = in_array(strtolower($current), ['active','enabled','1'], true) ? 'inactive' : 'active';
            }
            $stmt = $pdo->prepare("UPDATE `users` SET `{$statusCol}` = :status" . ($updatedCol ? ", `{$updatedCol}` = NOW()" : '') . " WHERE `{$idCol}` = :id");
            $stmt->execute([':status' => $newValue, ':id' => $recordId]);
            $message = 'เปลี่ยนสถานะ User เรียบร้อยแล้ว';
        } elseif (in_array($action, ['create','update'], true)) {
            $values = [];
            $bind = [];
            $add = static function (?string $col, $value) use (&$values, &$bind): void {
                if (!$col) return;
                $key = ':v_' . $col;
                $values[$col] = $key;
                $bind[$key] = trim((string)$value);
            };

            $add($usernameCol, $_POST['username'] ?? '');
            $add($employeeCol, $_POST['employee_code'] ?? '');
            $add($fullNameCol, $_POST['full_name'] ?? '');
            $add($firstNameCol, $_POST['first_name'] ?? '');
            $add($lastNameCol, $_POST['last_name'] ?? '');
            $add($emailCol, $_POST['email'] ?? '');

            if ($statusCol) {
                $meta = $columns[$statusCol];
                $statusRaw = $_POST['status'] ?? 'active';
                $statusValue = in_array($meta['DATA_TYPE'], ['tinyint','int','smallint','bigint','bit'], true)
                    ? userAdminBoolValue($statusRaw)
                    : trim((string)$statusRaw);
                $add($statusCol, $statusValue);
            }

            $password = (string)($_POST['password'] ?? '');
            if ($password !== '') {
                if (mb_strlen($password) < 4) throw new RuntimeException('รหัสผ่านต้องมีอย่างน้อย 4 ตัวอักษร');
                $add($passwordCol, password_hash($password, PASSWORD_DEFAULT));
            } elseif ($action === 'create' && $passwordCol) {
                throw new RuntimeException('กรุณากำหนดรหัสผ่านสำหรับ User ใหม่');
            }

            if ($usernameCol && trim((string)($_POST['username'] ?? '')) === '') throw new RuntimeException('กรุณากรอก Username');
            if (!$values) throw new RuntimeException('ไม่พบคอลัมน์ที่รองรับการบันทึก');

            if ($action === 'create') {
                $cols = array_keys($values);
                $sql = "INSERT INTO `users` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_values($values)) . ")";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($bind);
                $message = 'เพิ่ม User ใหม่เรียบร้อยแล้ว';
            } else {
                if (!$idCol || $recordId === '') throw new RuntimeException('ไม่พบรหัส User ที่ต้องการแก้ไข');
                $set = [];
                foreach ($values as $col => $placeholder) $set[] = "`{$col}` = {$placeholder}";
                if ($updatedCol) $set[] = "`{$updatedCol}` = NOW()";
                $bind[':record_id'] = $recordId;
                $stmt = $pdo->prepare("UPDATE `users` SET " . implode(', ', $set) . " WHERE `{$idCol}` = :record_id");
                $stmt->execute($bind);
                $message = 'แก้ไขข้อมูล User เรียบร้อยแล้ว';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$keyword = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$selectCols = array_keys($columns);
$where = [];
$params = [];
if ($keyword !== '') {
    $searchCols = array_values(array_filter([$usernameCol,$employeeCol,$fullNameCol,$firstNameCol,$lastNameCol,$emailCol]));
    if ($searchCols) {
        $parts = [];
        foreach ($searchCols as $i => $col) {
            $parts[] = "CAST(`{$col}` AS CHAR) LIKE :q{$i}";
            $params[":q{$i}"] = '%' . $keyword . '%';
        }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }
}
if ($statusFilter !== '' && $statusCol) { $where[] = "CAST(`{$statusCol}` AS CHAR) = :status"; $params[':status'] = $statusFilter; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM `users`" . $whereSql);
$countStmt->execute($params);
$totalFilteredUsers = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalFilteredUsers / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql = "SELECT * FROM `users`" . $whereSql . ($idCol ? " ORDER BY `{$idCol}` DESC" : '') . " LIMIT {$perPage} OFFSET {$offset}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statusValues = [];
if ($statusCol) {
    try { $statusValues = $pdo->query("SELECT DISTINCT CAST(`{$statusCol}` AS CHAR) FROM `users` WHERE `{$statusCol}` IS NOT NULL ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
}

$totalUsers = $totalFilteredUsers;
$activeUsers = 0;
foreach ($users as $u) {
    $status = strtolower(trim((string)($statusCol ? ($u[$statusCol] ?? '') : 'active')));
    if ($statusCol === null || in_array($status, ['1','active','enabled','yes','true'], true)) $activeUsers++;
}

$inactiveUsers = max(0, $totalUsers - $activeUsers);

$pageTitle = 'จัดการข้อมูล User';
require_once __DIR__ . '/../../../includes/header.php';

require_login();
require_permission('user.manage');

?>
<style>
.user-admin-page{padding:10px 14px 22px}.user-admin-hero{background:linear-gradient(135deg,#0b3c68,#1769aa);color:#fff;border-radius:18px;padding:17px 20px;display:flex;justify-content:space-between;align-items:center;gap:12px;box-shadow:0 10px 28px rgba(15,76,129,.18);margin-bottom:12px}.user-admin-hero h1{font-size:1.3rem;font-weight:900;margin:0 0 3px}.user-admin-hero p{font-size:.8rem;opacity:.86;margin:0}.user-stat-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:12px}.user-stat{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px 14px}.user-stat span{display:block;color:#64748b;font-size:.7rem;font-weight:800}.user-stat strong{font-size:1.35rem;color:#0f4c81}.user-panel{background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 5px 18px rgba(15,23,42,.05);overflow:hidden}.user-filter{display:grid;grid-template-columns:minmax(260px,1fr) 180px auto auto;gap:8px;padding:12px;border-bottom:1px solid #e2e8f0}.user-filter .form-control,.user-filter .form-select{min-height:38px;font-size:.8rem}.user-table-wrap{height:auto;max-height:none;min-height:0;overflow-y:visible;overflow-x:auto}.user-table{margin:0;width:100%;min-width:0;table-layout:fixed}.user-table th{position:sticky;top:0;z-index:2;background:#f8fafc;font-size:.68rem;white-space:nowrap;padding:.42rem .28rem}.user-table td{font-size:.7rem;vertical-align:middle;padding:.4rem .28rem;overflow-wrap:anywhere;word-break:break-word}.user-table th:nth-child(1),.user-table td:nth-child(1){width:5%;text-align:center}.user-table th:nth-child(2),.user-table td:nth-child(2){width:22%}.user-table th:nth-child(3),.user-table td:nth-child(3){width:12%}.user-table th:nth-child(4),.user-table td:nth-child(4){width:22%}.user-table th:nth-child(5),.user-table td:nth-child(5){width:11%;text-align:center}.user-table th:nth-child(6),.user-table td:nth-child(6){width:13%}.user-table th:nth-child(7),.user-table td:nth-child(7){width:15%}.user-avatar{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#dbeafe;color:#1d4ed8;font-weight:900;flex:0 0 28px;font-size:.72rem}.user-name{font-weight:900;color:#0f172a;line-height:1.15}.user-sub{font-size:.62rem;color:#64748b;line-height:1.1}.user-table td:nth-child(3),.user-table td:nth-child(5),.user-table td:nth-child(6){white-space:nowrap}.user-table td:nth-child(4){overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.role-badge,.status-badge{display:inline-flex;border-radius:999px;padding:.28rem .55rem;font-size:.64rem;font-weight:900;white-space:nowrap}.role-badge{background:#e0e7ff;color:#3730a3}.status-active{background:#dcfce7;color:#166534}.status-inactive{background:#fee2e2;color:#991b1b}.user-actions{display:flex;gap:3px;flex-wrap:nowrap;align-items:center;justify-content:center}.user-actions form{display:inline;margin:0}.user-actions .btn{font-size:.58rem;padding:.24rem .3rem;white-space:nowrap;line-height:1.1}.user-modal .modal-dialog{max-width:760px}.user-modal .modal-content{border:0;border-radius:14px;overflow:hidden}.user-modal .modal-header{background:linear-gradient(135deg,#eff6ff,#fff);padding:7px 12px}.user-modal .modal-header .modal-title{font-size:.95rem;line-height:1.15}.user-modal .modal-header .small{font-size:.66rem}.user-modal .modal-body{background:#f8fafc;padding:8px}.user-modal .modal-footer{padding:6px 10px;gap:6px;background:#fff}.user-modal .modal-footer .btn{font-size:.72rem;padding:.32rem .75rem}.user-form-table-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden}.user-form-table{margin:0;font-size:.72rem}.user-form-table th{width:180px;background:#f8fafc;color:#334155;font-weight:800;vertical-align:middle;padding:.38rem .55rem;white-space:nowrap}.user-form-table td{padding:.3rem .5rem;vertical-align:middle}.user-form-table .form-control,.user-form-table .form-select{min-height:31px;height:31px;font-size:.72rem;padding:.25rem .5rem}.user-form-table .form-text{font-size:.62rem;line-height:1.2;margin-top:3px}.permission-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.permission-item{border:1px solid #e2e8f0;border-radius:10px;padding:9px;background:#f8fafc}.permission-item label{font-size:.72rem;font-weight:800;margin-left:5px}@media(max-width:1366px){.user-admin-page{padding:8px}.user-table-wrap{height:auto;max-height:none;min-height:0;overflow-y:visible;overflow-x:auto}.user-filter{grid-template-columns:minmax(220px,1fr) 150px auto auto}.user-table th{font-size:.62rem;padding:.34rem .2rem}.user-table td{font-size:.64rem;padding:.34rem .2rem}.user-actions{gap:2px}.user-actions .btn{font-size:.52rem;padding:.2rem .24rem}.user-modal .modal-dialog{max-width:760px}}@media(max-width:900px){.user-filter{grid-template-columns:1fr 1fr}.user-stat-row{grid-template-columns:1fr}.permission-grid{grid-template-columns:1fr 1fr}.user-admin-hero{align-items:flex-start;flex-direction:column}}@media(max-width:600px){.user-filter,.permission-grid{grid-template-columns:1fr}}
</style>

<link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/hdd-primary-action-button.css">

<div class="user-admin-page">
  <div class="user-admin-hero">
    <div><h1>จัดการข้อมูล User</h1><p>เพิ่ม แก้ไข เปิด/ปิดบัญชี และรีเซ็ตรหัสผ่าน โดยจัดการ Role และ Permission จากหน้าสิทธิ์ส่วนกลาง</p></div>
    <div class="d-flex gap-2"><a class="btn btn-light hdd-primary-action-btn" href="../permissions/index.php">จัดการสิทธิ์ส่วนกลาง</a><button type="button" class="btn btn-light hdd-primary-action-btn" data-bs-toggle="modal" data-bs-target="#userModal" id="addUserBtn">+ เพิ่ม User</button></div>
  </div>
  <?php if ($message): ?><div class="alert alert-success py-2"><?php echo userAdminEscape($message); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger py-2"><?php echo userAdminEscape($error); ?></div><?php endif; ?>
  <div class="user-stat-row">
    <div class="user-stat"><span>User ที่แสดง</span><strong><?php echo number_format($totalUsers); ?></strong></div>
    <div class="user-stat"><span>บัญชีที่ใช้งาน</span><strong><?php echo number_format($activeUsers); ?></strong></div>
    <div class="user-stat"><span>บัญชีที่ปิดใช้งาน</span><strong><?php echo number_format($inactiveUsers); ?></strong></div>
  </div>
  <div class="user-panel">
    <form class="user-filter" method="get">
      <input class="form-control" name="q" value="<?php echo userAdminEscape($keyword); ?>" placeholder="ค้นหา Username, รหัสพนักงาน, ชื่อ หรือ Email">
      <select class="form-select" name="status"><option value="">สถานะทั้งหมด</option><?php foreach ($statusValues as $s): ?><option value="<?php echo userAdminEscape($s); ?>" <?php echo $statusFilter===(string)$s?'selected':''; ?>><?php echo userAdminEscape($s); ?></option><?php endforeach; ?></select>
      <button class="btn btn-primary">ค้นหา</button><a class="btn btn-outline-secondary" href="index.php">ล้างค่า</a>
    </form>
    <div class="user-table-wrap table-responsive">
      <table class="table table-hover table-bordered user-table align-middle">
        <thead><tr><th>ลำดับ</th><th>ผู้ใช้งาน</th><th>รหัสพนักงาน</th><th>Email</th><th>สถานะ</th><th>เข้าใช้ล่าสุด</th><th>จัดการ</th></tr></thead>
        <tbody>
        <?php if (!$users): ?><tr><td colspan="7" class="text-center text-muted py-4">ไม่พบข้อมูล User</td></tr><?php endif; ?>
        <?php foreach ($users as $i => $u):
          $id = $idCol ? ($u[$idCol] ?? '') : $i;
          $username = $usernameCol ? ($u[$usernameCol] ?? '-') : '-';
          $fullName = $fullNameCol ? ($u[$fullNameCol] ?? '') : trim((string)($firstNameCol ? ($u[$firstNameCol] ?? '') : '') . ' ' . (string)($lastNameCol ? ($u[$lastNameCol] ?? '') : ''));
          $employee = $employeeCol ? ($u[$employeeCol] ?? '-') : '-';
          $email = $emailCol ? ($u[$emailCol] ?? '-') : '-';
          $status = $statusCol ? ($u[$statusCol] ?? '') : 'active';
          $isActive = !$statusCol || in_array(strtolower((string)$status), ['1','active','enabled','true','yes'], true);
          $lastLogin = $lastLoginCol ? ($u[$lastLoginCol] ?? '-') : '-';
          $payload = [];
          foreach ([$idCol,$usernameCol,$employeeCol,$fullNameCol,$firstNameCol,$lastNameCol,$emailCol,$statusCol] as $c) if ($c) $payload[$c] = $u[$c] ?? '';
        ?>
        <tr>
          <td class="text-center"><?php echo $offset + $i + 1; ?></td>
          <td><div><div class="user-name"><?php echo userAdminEscape($fullName ?: $username); ?></div><div class="user-sub"><?php echo userAdminEscape($username); ?></div></div></td>
          <td><?php echo userAdminEscape($employee); ?></td><td><?php echo userAdminEscape($email); ?></td>
          <td><span class="status-badge <?php echo $isActive?'status-active':'status-inactive'; ?>"><?php echo $isActive?'ใช้งาน':'ปิดใช้งาน'; ?></span></td>
          <td><?php echo userAdminEscape($lastLogin); ?></td>
          <td><div class="user-actions">
            <button type="button" class="btn btn-outline-primary js-edit-user" data-user='<?php echo userAdminEscape(json_encode($payload, JSON_UNESCAPED_UNICODE)); ?>' data-bs-toggle="modal" data-bs-target="#userModal"><svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" role="img" aria-label="แก้ไข"><path d="M12.146.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-10 10A.5.5 0 0 1 5.5 14H2a.5.5 0 0 1-.5-.5V10a.5.5 0 0 1 .146-.354zM11.207 2.5 13.5 4.793 14.793 3.5 12.5 1.207zM12.793 5.5 10.5 3.207 4 9.707V10h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.293zM3.5 10.207 2.5 11.207V13h1.793l1-1H5.5v-.5H5a.5.5 0 0 1-.5-.5v-.5H4a.5.5 0 0 1-.5-.5z"/></svg></button>
            <a class="btn btn-outline-info" href="../permissions/index.php?user_key=<?php echo urlencode((string)$id); ?>#user-role-section">สิทธิ์</a>
            <?php if ($statusCol): ?><form method="post"><input type="hidden" name="csrf_token" value="<?php echo userAdminEscape($_SESSION['csrf_token']); ?>"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="record_id" value="<?php echo userAdminEscape($id); ?>"><input type="hidden" name="current_status" value="<?php echo userAdminEscape($status); ?>"><button class="btn btn-outline-warning"><?php echo $isActive?'ปิดบัญชี':'เปิดบัญชี'; ?></button></form><?php endif; ?>
            <form method="post" onsubmit="return confirm('ยืนยันลบ User นี้?');"><input type="hidden" name="csrf_token" value="<?php echo userAdminEscape($_SESSION['csrf_token']); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="record_id" value="<?php echo userAdminEscape($id); ?>"><button class="btn btn-outline-danger" title="ลบ" aria-label="ลบ"><svg class="action-icon" width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/><path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1 0-2H5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1h2.5a1 1 0 0 1 1 1M4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM6.5 2a.5.5 0 0 0-.5.5V3h4v-.5a.5.5 0 0 0-.5-.5z"/></svg></button></form>
          </div></td>
        </tr><?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2 border-top bg-light">
      <div class="small text-muted">แสดง <?php echo number_format($offset + 1); ?>-<?php echo number_format(min($offset + $perPage, $totalFilteredUsers)); ?> จาก <?php echo number_format($totalFilteredUsers); ?> รายการ</div>
      <nav aria-label="แบ่งหน้ารายการ User">
        <ul class="pagination pagination-sm mb-0">
          <?php
          $queryBase = ['q' => $keyword, 'status' => $statusFilter];
          $startPage = max(1, $page - 2);
          $endPage = min($totalPages, $page + 2);
          ?>
          <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo userAdminEscape(http_build_query(array_merge($queryBase, ['page' => max(1, $page - 1)]))); ?>">ก่อนหน้า</a></li>
          <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
          <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo userAdminEscape(http_build_query(array_merge($queryBase, ['page' => $p]))); ?>"><?php echo $p; ?></a></li>
          <?php endfor; ?>
          <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo userAdminEscape(http_build_query(array_merge($queryBase, ['page' => min($totalPages, $page + 1)]))); ?>">ถัดไป</a></li>
        </ul>
      </nav>
    </div>
    <?php endif; ?>
  </div>
</div>
<div class="modal fade user-modal" id="userModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
 <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><form method="post" class="modal-content" id="userForm" autocomplete="off">
  <div class="modal-header"><div><h5 class="modal-title fw-bold" id="userModalTitle">เพิ่ม User</h5><div class="small text-muted">กำหนดข้อมูลบัญชีผู้ใช้งาน</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
   <input type="hidden" name="csrf_token" value="<?php echo userAdminEscape($_SESSION['csrf_token']); ?>"><input type="hidden" name="action" id="formAction" value="create"><input type="hidden" name="record_id" id="recordId">
   <div class="table-responsive user-form-table-wrap">
    <table class="table table-bordered table-sm user-form-table">
     <tbody>
      <?php if ($usernameCol): ?><tr><th>Username <span class="text-danger">*</span></th><td><input class="form-control" name="username" id="f_username" required></td></tr><?php endif; ?>
      <?php if ($employeeCol): ?><tr><th>รหัสพนักงาน</th><td><input class="form-control" name="employee_code" id="f_employee_code"></td></tr><?php endif; ?>
      <?php if ($fullNameCol): ?><tr><th>ชื่อ-นามสกุล</th><td><input class="form-control" name="full_name" id="f_full_name"></td></tr><?php else: ?><?php if ($firstNameCol): ?><tr><th>ชื่อ</th><td><input class="form-control" name="first_name" id="f_first_name"></td></tr><?php endif; ?><?php if ($lastNameCol): ?><tr><th>นามสกุล</th><td><input class="form-control" name="last_name" id="f_last_name"></td></tr><?php endif; ?><?php endif; ?>
      <?php if ($emailCol): ?><tr><th>Email</th><td><input type="email" class="form-control" name="email" id="f_email"></td></tr><?php endif; ?>
      <?php if ($statusCol): ?><tr><th>สถานะบัญชี</th><td><select class="form-select" name="status" id="f_status"><option value="active">ใช้งาน</option><option value="inactive">ปิดใช้งาน</option><option value="1">ใช้งาน (1)</option><option value="0">ปิดใช้งาน (0)</option></select></td></tr><?php endif; ?>
      <?php if ($passwordCol): ?><tr><th>รหัสผ่าน <span id="passwordRequired" class="text-danger">*</span></th><td><input type="password" class="form-control" name="password" id="f_password" minlength="4"><div class="form-text">อย่างน้อย 4 ตัวอักษร; ตอนแก้ไขให้เว้นว่าง หากไม่ต้องการเปลี่ยนรหัสผ่าน</div></td></tr><?php endif; ?>
     </tbody>
    </table>
   </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button><button class="btn btn-primary px-4">บันทึกข้อมูล</button></div>
 </form></div>
</div>
<script>
document.addEventListener('DOMContentLoaded',function(){
 const form=document.getElementById('userForm'); const addBtn=document.getElementById('addUserBtn');
 function resetForm(){form.reset();document.getElementById('formAction').value='create';document.getElementById('recordId').value='';document.getElementById('userModalTitle').textContent='เพิ่ม User';const pw=document.getElementById('f_password');if(pw)pw.required=true;const pr=document.getElementById('passwordRequired');if(pr)pr.style.display='inline';}
 if(addBtn)addBtn.addEventListener('click',resetForm);
 document.querySelectorAll('.js-edit-user').forEach(btn=>btn.addEventListener('click',function(){resetForm();const d=JSON.parse(this.dataset.user||'{}');document.getElementById('formAction').value='update';document.getElementById('recordId').value=d[<?php echo json_encode($idCol); ?>]||'';document.getElementById('userModalTitle').textContent='แก้ไข User';
 const map={f_username:<?php echo json_encode($usernameCol); ?>,f_employee_code:<?php echo json_encode($employeeCol); ?>,f_full_name:<?php echo json_encode($fullNameCol); ?>,f_first_name:<?php echo json_encode($firstNameCol); ?>,f_last_name:<?php echo json_encode($lastNameCol); ?>,f_email:<?php echo json_encode($emailCol); ?>,f_status:<?php echo json_encode($statusCol); ?>};Object.entries(map).forEach(([id,col])=>{const el=document.getElementById(id);if(el&&col)el.value=d[col]??'';});
 const pw=document.getElementById('f_password');if(pw){pw.required=false;pw.value='';}const pr=document.getElementById('passwordRequired');if(pr)pr.style.display='none';}));
});
</script>

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

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
