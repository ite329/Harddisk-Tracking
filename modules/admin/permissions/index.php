<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';

require_login();
require_permission('permission.manage');

$pageTitle = 'จัดการสิทธิ์ส่วนกลาง';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

function permE($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }
function permAudit(PDO $pdo, string $action, string $targetType, string $targetKey, $old, $new): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO permission_audit_logs (action_type,target_type,target_key,old_value,new_value,performed_by,ip_address) VALUES (:a,:t,:k,:o,:n,:u,:ip)");
        $stmt->execute([
            ':a'=>$action, ':t'=>$targetType, ':k'=>$targetKey,
            ':o'=>$old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            ':n'=>$new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
            ':u'=>central_permission_user_key(), ':ip'=>$_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {}
}

$message=''; $error='';
$selectedUserKey = trim((string)($_GET['user_key'] ?? ''));
if (!permission_tables_ready($pdo)) {
    $error = 'ยังไม่ได้ติดตั้งตารางสิทธิ์ กรุณารันไฟล์ database/020_central_permissions.sql ก่อน';
} else {
    // Register module permissions so they appear in the central permission matrix.
    try {
        $permissionColumnsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'permissions'");
        $permissionColumnsStmt->execute();
        $permissionColumns = array_map('strtolower', $permissionColumnsStmt->fetchAll(PDO::FETCH_COLUMN));
        $drumPermissions = [
            ['code' => 'drum_requests.edit', 'name' => 'แก้ไขรายการเบิก Drum', 'module' => 'drum_requests', 'description' => 'อนุญาตให้แก้ไขข้อมูลในตารางรายการเบิก Drum ทั้งหมด'],
            ['code' => 'drum_requests.delete', 'name' => 'ลบรายการเบิก Drum', 'module' => 'drum_requests', 'description' => 'อนุญาตให้ลบข้อมูลในตารางรายการเบิก Drum ทั้งหมด'],



];
        foreach ($drumPermissions as $drumPermission) {
            $checkPermission = $pdo->prepare('SELECT id FROM permissions WHERE permission_code = :code LIMIT 1');
            $checkPermission->execute([':code' => $drumPermission['code']]);
            if ($checkPermission->fetchColumn()) {
                continue;
            }
            $insertColumns = ['permission_code', 'permission_name'];
            $insertValues = [':code', ':name'];
            $insertParams = [':code' => $drumPermission['code'], ':name' => $drumPermission['name']];
            if (in_array('module_code', $permissionColumns, true)) {
                $insertColumns[] = 'module_code';
                $insertValues[] = ':module';
                $insertParams[':module'] = $drumPermission['module'];
            }
            if (in_array('description', $permissionColumns, true)) {
                $insertColumns[] = 'description';
                $insertValues[] = ':description';
                $insertParams[':description'] = $drumPermission['description'];
            }
            if (in_array('is_active', $permissionColumns, true)) {
                $insertColumns[] = 'is_active';
                $insertValues[] = '1';
            }
            $insertPermission = $pdo->prepare('INSERT INTO permissions (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $insertValues) . ')');
            $insertPermission->execute($insertParams);
        }
    } catch (Throwable $e) {
        error_log('[central_permissions] Cannot register module permissions: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $error==='') {
    try {
        if (!hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('CSRF Token ไม่ถูกต้อง');
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'save_role') {
            $roleId=(int)($_POST['role_id'] ?? 0);
            $code=trim((string)($_POST['role_code'] ?? ''));
            $name=trim((string)($_POST['role_name'] ?? ''));
            if ($code==='' || $name==='') throw new RuntimeException('กรุณากรอก Role Code และชื่อ Role');
            if (!preg_match('/^[a-z0-9_]+$/', $code)) throw new RuntimeException('Role Code ใช้ได้เฉพาะ a-z, 0-9 และ _');
            if ($roleId>0) {
                $old=$pdo->prepare('SELECT * FROM roles WHERE id=:id'); $old->execute([':id'=>$roleId]); $oldRow=$old->fetch(PDO::FETCH_ASSOC);
                $stmt=$pdo->prepare('UPDATE roles SET role_code=:c,role_name=:n,description=:d,is_active=:a WHERE id=:id');
                $stmt->execute([':c'=>$code,':n'=>$name,':d'=>trim((string)($_POST['description'] ?? '')),':a'=>isset($_POST['is_active'])?1:0,':id'=>$roleId]);
                permAudit($pdo,'update','role',(string)$roleId,$oldRow,$_POST);
                $message='แก้ไข Role เรียบร้อยแล้ว';
            } else {
                $stmt=$pdo->prepare('INSERT INTO roles (role_code,role_name,description,is_active) VALUES (:c,:n,:d,1)');
                $stmt->execute([':c'=>$code,':n'=>$name,':d'=>trim((string)($_POST['description'] ?? ''))]);
                permAudit($pdo,'create','role',(string)$pdo->lastInsertId(),null,$_POST);
                $message='เพิ่ม Role เรียบร้อยแล้ว';
            }
        } elseif ($action === 'save_role_permissions') {
            $roleId=(int)($_POST['role_id'] ?? 0);
            if ($roleId<=0) throw new RuntimeException('ไม่พบ Role');
            $newIds=array_values(array_unique(array_map('intval', $_POST['permission_ids'] ?? [])));
            $oldStmt=$pdo->prepare('SELECT permission_id FROM role_permissions WHERE role_id=:id'); $oldStmt->execute([':id'=>$roleId]); $oldIds=array_map('intval',$oldStmt->fetchAll(PDO::FETCH_COLUMN));
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM role_permissions WHERE role_id=:id')->execute([':id'=>$roleId]);
            $ins=$pdo->prepare('INSERT INTO role_permissions (role_id,permission_id) VALUES (:r,:p)');
            foreach ($newIds as $pid) if ($pid>0) $ins->execute([':r'=>$roleId,':p'=>$pid]);
            $pdo->commit();
            permAudit($pdo,'update_permissions','role',(string)$roleId,$oldIds,$newIds);
            $message='บันทึกสิทธิ์ของ Role เรียบร้อยแล้ว';
        } elseif ($action === 'save_user_roles') {
            $userKey=trim((string)($_POST['user_key'] ?? ''));
            if ($userKey==='') throw new RuntimeException('กรุณาเลือก User');
            $newIds=array_values(array_unique(array_map('intval', $_POST['role_ids'] ?? [])));
            $oldStmt=$pdo->prepare('SELECT role_id FROM user_roles WHERE user_key=:u'); $oldStmt->execute([':u'=>$userKey]); $oldIds=array_map('intval',$oldStmt->fetchAll(PDO::FETCH_COLUMN));
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM user_roles WHERE user_key=:u')->execute([':u'=>$userKey]);
            $ins=$pdo->prepare('INSERT INTO user_roles (user_key,role_id) VALUES (:u,:r)');
            foreach ($newIds as $rid) if ($rid>0) $ins->execute([':u'=>$userKey,':r'=>$rid]);
            $pdo->commit();
            permAudit($pdo,'assign_roles','user',$userKey,$oldIds,$newIds);
            if ($userKey===central_permission_user_key()) boot_user_permissions($pdo,true);
            $message='กำหนด Role ให้ User เรียบร้อยแล้ว';
        } elseif ($action === 'save_user_permissions') {
            $userKey=trim((string)($_POST['user_key'] ?? ''));
            if ($userKey==='') throw new RuntimeException('ไม่พบ User ที่ต้องการกำหนดสิทธิ์');
            $permissionTypes=is_array($_POST['permission_type'] ?? null) ? $_POST['permission_type'] : [];
            $oldStmt=$pdo->prepare('SELECT permission_id,permission_type FROM user_permissions WHERE user_key=:u ORDER BY permission_id');
            $oldStmt->execute([':u'=>$userKey]);
            $oldRows=$oldStmt->fetchAll(PDO::FETCH_ASSOC);
            $newRows=[];
            foreach ($permissionTypes as $permissionId=>$permissionType) {
                $permissionId=(int)$permissionId;
                $permissionType=trim((string)$permissionType);
                if ($permissionId>0 && in_array($permissionType,['allow','deny'],true)) {
                    $newRows[]=['permission_id'=>$permissionId,'permission_type'=>$permissionType];
                }
            }
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM user_permissions WHERE user_key=:u')->execute([':u'=>$userKey]);
            $ins=$pdo->prepare('INSERT INTO user_permissions (user_key,permission_id,permission_type) VALUES (:u,:p,:t)');
            foreach ($newRows as $row) {
                $ins->execute([':u'=>$userKey,':p'=>$row['permission_id'],':t'=>$row['permission_type']]);
            }
            $pdo->commit();
            permAudit($pdo,'assign_permissions','user',$userKey,$oldRows,$newRows);
            if ($userKey===central_permission_user_key()) boot_user_permissions($pdo,true);
            $message='บันทึกสิทธิ์ราย User เรียบร้อยแล้ว';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error=$e->getMessage();
    }
}

$roles=[]; $permissions=[]; $rolePermissionMap=[]; $users=[]; $userRoleMap=[]; $userPermissionOverrideMap=[]; $userEffectivePermissionMap=[]; $permissionById=[];
if ($error==='' || permission_tables_ready($pdo)) {
    $roles=$pdo->query('SELECT * FROM roles ORDER BY role_name')->fetchAll(PDO::FETCH_ASSOC);
    $permissions=$pdo->query('SELECT * FROM permissions WHERE is_active=1 ORDER BY module_code, permission_name')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pdo->query('SELECT role_id,permission_id FROM role_permissions')->fetchAll(PDO::FETCH_ASSOC) as $r) $rolePermissionMap[(int)$r['role_id']][]=(int)$r['permission_id'];
    try {
        $userColumnStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
        $userColumnStmt->execute();
        $userColumns = $userColumnStmt->fetchAll(PDO::FETCH_COLUMN);
        $hasUserColumn = static function (string $column) use ($userColumns): bool {
            return in_array($column, $userColumns, true);
        };

        $displayNameParts = [];
        foreach (['full_name', 'name_th', 'display_name', 'name'] as $column) {
            if ($hasUserColumn($column)) {
                $displayNameParts[] = "NULLIF(TRIM(`{$column}`), '')";
            }
        }

        $firstNameColumn = '';
        foreach (['first_name', 'firstname', 'fname'] as $column) {
            if ($hasUserColumn($column)) {
                $firstNameColumn = $column;
                break;
            }
        }

        $lastNameColumn = '';
        foreach (['last_name', 'lastname', 'lname', 'surname'] as $column) {
            if ($hasUserColumn($column)) {
                $lastNameColumn = $column;
                break;
            }
        }

        if ($firstNameColumn !== '' || $lastNameColumn !== '') {
            $firstNameSql = $firstNameColumn !== '' ? "NULLIF(TRIM(`{$firstNameColumn}`), '')" : "''";
            $lastNameSql = $lastNameColumn !== '' ? "NULLIF(TRIM(`{$lastNameColumn}`), '')" : "''";
            $displayNameParts[] = "NULLIF(TRIM(CONCAT_WS(' ', {$firstNameSql}, {$lastNameSql})), '')";
        }

        if ($hasUserColumn('employee_code')) {
            $displayNameParts[] = "NULLIF(TRIM(`employee_code`), '')";
        }

        $displayNameSql = !empty($displayNameParts)
            ? 'COALESCE(' . implode(', ', $displayNameParts) . ", '-') AS display_name"
            : "'-' AS display_name";

        $selectUserColumns = [];
        foreach (['id', 'employee_code', 'role'] as $column) {
            if ($hasUserColumn($column)) {
                $selectUserColumns[] = "`{$column}`";
            }
        }
        $selectUserColumns[] = $displayNameSql;

        $orderUserSql = $hasUserColumn('employee_code') ? '`employee_code` ASC' : ($hasUserColumn('id') ? '`id` ASC' : 'display_name ASC');
        $users = $pdo->query('SELECT ' . implode(', ', $selectUserColumns) . ' FROM users ORDER BY ' . $orderUserSql . ' LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $users=$pdo->query('SELECT * FROM users LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($pdo->query('SELECT user_key,role_id FROM user_roles')->fetchAll(PDO::FETCH_ASSOC) as $r) $userRoleMap[(string)$r['user_key']][]=(int)$r['role_id'];
    foreach ($permissions as $permission) $permissionById[(int)$permission['id']]=$permission;
    foreach ($pdo->query('SELECT user_key,permission_id,permission_type FROM user_permissions')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $userPermissionOverrideMap[(string)$r['user_key']][(int)$r['permission_id']]=(string)$r['permission_type'];
    }
    foreach ($users as $user) {
        $userKey=(string)($user['id']??($user['employee_code']??''));
        if ($userKey==='') continue;
        $effective=[];
        foreach ($userRoleMap[$userKey]??[] as $roleId) {
            foreach ($rolePermissionMap[$roleId]??[] as $permissionId) $effective[(int)$permissionId]=true;
        }
        foreach ($userPermissionOverrideMap[$userKey]??[] as $permissionId=>$permissionType) {
            if ($permissionType==='deny') unset($effective[(int)$permissionId]);
            else $effective[(int)$permissionId]=true;
        }
        $userEffectivePermissionMap[$userKey]=array_keys($effective);
    }
}

require_once __DIR__ . '/../../../includes/header.php';
?>
<style>
.permission-page{padding:0 12px 24px}.permission-hero{background:linear-gradient(135deg,#0b3c68,#1769aa);color:#fff;border-radius:18px;padding:18px 20px;margin-bottom:14px}.permission-card{border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 6px 20px rgba(15,23,42,.06);overflow:hidden}.permission-card .card-header{background:#fbfdff;font-weight:900;border-bottom:1px solid #e2e8f0}.permission-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.permission-item{border:1px solid #e2e8f0;border-radius:10px;padding:8px;background:#fff}.module-title{font-size:.75rem;color:#64748b;font-weight:900;text-transform:uppercase}.role-box{border:1px solid #e2e8f0;border-radius:12px;padding:10px}.small-check{font-size:.78rem}

/* ตารางกำหนด Role ให้ User - ปรับเฉพาะ UI ไม่แตะ Logic */
.user-role-card .card-header{padding:12px 16px;background:linear-gradient(180deg,#f8fbff,#f1f5f9)}
.user-role-card .card-body{padding:0}
.user-role-toolbar{display:grid;grid-template-columns:minmax(280px,1fr) auto;gap:10px;padding:12px 14px;background:#fff;border-bottom:1px solid #e2e8f0}
.user-role-toolbar .form-label{font-size:.72rem;font-weight:800;color:#475569;margin-bottom:5px}
.user-role-table-wrap{overflow-x:auto}
.user-role-table{margin:0;table-layout:fixed;min-width:760px}
.user-role-table thead th{background:#0f4c81;color:#fff;border-color:#1e5f96;font-size:.72rem;font-weight:800;white-space:nowrap;padding:.62rem .55rem;vertical-align:middle}
.user-role-table tbody td{font-size:.74rem;padding:.55rem;vertical-align:middle;border-color:#e2e8f0}
.user-role-table tbody td:nth-child(2){padding-left:.75rem;padding-right:.75rem}
.user-role-table tbody tr:nth-child(even){background:#f8fafc}
.user-role-table tbody tr:hover{background:#eef6ff}
.user-role-col-user{width:30%}
.user-role-col-roles{width:58%}
.user-role-col-action{width:12%;text-align:center}
.user-role-userbox{display:flex;align-items:center;gap:8px}
.user-role-usericon{width:30px;height:30px;border-radius:9px;background:#dbeafe;color:#1d4ed8;display:flex;align-items:center;justify-content:center;flex:0 0 30px}
.user-role-usertext{min-width:0}
.user-role-usertext strong{display:block;color:#0f172a;font-size:.76rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role-usertext small{display:block;color:#64748b;font-size:.66rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role-checks{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.user-role-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid #cbd5e1;border-radius:999px;padding:.34rem .7rem;background:#fff;font-size:.72rem;font-weight:700;white-space:nowrap;cursor:pointer;min-width:max-content;line-height:1.2}
.user-role-chip:hover{border-color:#60a5fa;background:#eff6ff}
.user-role-chip .form-check-input{margin:0}
.user-role-actions{padding:11px 14px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:right}
.user-role-actions .btn{min-width:150px}
@media(max-width:992px){.permission-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.user-role-toolbar{grid-template-columns:1fr}}
@media(max-width:600px){.permission-grid{grid-template-columns:1fr}.user-role-table{min-width:680px}}
.user-permission-table-wrap{overflow:auto;max-height:620px}.user-permission-table{min-width:980px;margin:0}.user-permission-table th{position:sticky;top:0;z-index:2;background:#0f4c81;color:#fff;font-size:.72rem;white-space:nowrap}.user-permission-table td{font-size:.73rem;vertical-align:middle}.user-permission-badges{display:flex;flex-wrap:wrap;gap:4px;max-width:760px}.user-permission-badge{font-size:.66rem;font-weight:700;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:.22rem .48rem}.permission-user-search{max-width:360px}.user-permission-modal .modal-dialog{max-width:1180px}.user-permission-editor{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.user-permission-editor-item{border:1px solid #e2e8f0;border-radius:10px;padding:9px;background:#fff}.user-permission-editor-item .form-select{font-size:.72rem}.permission-effective-note{font-size:.67rem;color:#64748b}@media(max-width:992px){.user-permission-editor{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.user-permission-editor{grid-template-columns:1fr}}
</style>

<link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/hdd-primary-action-button.css">

<div class="container-fluid permission-page">
<div class="permission-hero"><div class="d-flex justify-content-between align-items-center gap-2 flex-wrap"><div><h1 class="h5 fw-bold mb-1">จัดการสิทธิ์ส่วนกลาง</h1><div class="small opacity-75">จุดเดียวสำหรับกำหนด Role และ Permission ของผู้ใช้งาน</div></div><a class="btn btn-light hdd-primary-action-btn" href="../users/index.php">กลับหน้าจัดการ User</a></div></div>
<?php if($message!==''):?><div class="alert alert-success py-2"><?php echo permE($message);?></div><?php endif;?>
<?php if($error!==''):?><div class="alert alert-danger py-2"><?php echo permE($error);?></div><?php endif;?>
<?php if(permission_tables_ready($pdo)):?>
<div class="row g-3">
<div class="col-12" id="user-role-section">
  <div class="card permission-card user-role-card">
    <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
      <div>
        <div class="fw-bold">กำหนด Role ให้ User</div>
        <div class="small text-muted fw-normal">เลือกผู้ใช้งานและกำหนด Role ที่ต้องการ</div>
      </div>
      <span class="badge text-bg-light border"><?php echo number_format(count($users)); ?> User</span>
    </div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?php echo permE($_SESSION['csrf_token']);?>">
      <input type="hidden" name="action" value="save_user_roles">

      <div class="user-role-toolbar">
        <div>
          <label class="form-label">ค้นหาและเลือก User</label>
          <select name="user_key" id="permissionUserSelect" class="form-select" required>
            <option value="">-- เลือก User --</option>
            <?php foreach($users as $u):
                $key=(string)($u['id']??($u['employee_code']??''));
                $employeeCode=trim((string)($u['employee_code']??$key));
                $displayName=trim((string)($u['display_name']??($u['full_name']??'')));
                $userOptionText=$employeeCode;
                if ($displayName!=='' && $displayName!=='-' && $displayName!==$employeeCode) {
                    $userOptionText .= ' - ' . $displayName;
                }
            ?>
            <option value="<?php echo permE($key);?>" data-employee="<?php echo permE($employeeCode);?>" data-name="<?php echo permE($displayName);?>" data-roles="<?php echo permE(implode(',',$userRoleMap[$key]??[]));?>" <?php echo $selectedUserKey === $key ? 'selected' : ''; ?>><?php echo permE($userOptionText);?></option>
            <?php endforeach;?>
          </select>
        </div>
        <div class="d-flex align-items-end">
          <a href="../users/index.php" class="btn btn-outline-secondary">
            <i class="bi bi-people me-1"></i>จัดการข้อมูล User
          </a>
        </div>
      </div>

      <div class="user-role-table-wrap">
        <table class="table table-bordered user-role-table align-middle">
          <thead>
            <tr>
              <th class="user-role-col-user">ผู้ใช้งาน</th>
              <th class="user-role-col-roles">Role ที่กำหนด</th>
              <th class="user-role-col-action">สถานะ</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>
                <div class="user-role-userbox">
                  <div class="user-role-usericon"><i class="bi bi-person"></i></div>
                  <div class="user-role-usertext">
                    <strong id="selectedUserName">ยังไม่ได้เลือก User</strong>
                    <small id="selectedUserCode">กรุณาเลือกจากรายการด้านบน</small>
                  </div>
                </div>
              </td>
              <td>
                <div class="user-role-checks">
                  <?php foreach($roles as $role):?>
                  <label class="user-role-chip">
                    <input class="form-check-input user-role-check" type="checkbox" name="role_ids[]" value="<?php echo (int)$role['id'];?>">
                    <span><?php echo permE($role['role_name']);?></span>
                  </label>
                  <?php endforeach;?>
                </div>
              </td>
              <td class="text-center">
                <span id="selectedRoleCount" class="badge rounded-pill text-bg-secondary">0 Role</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="user-role-actions">
        <button class="btn btn-primary">
          <i class="bi bi-save me-1"></i>บันทึก Role ของ User
        </button>
      </div>
    </form>
  </div>
</div>


<div class="col-12" id="user-permission-matrix-section">
  <div class="card permission-card">
    <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
      <div><div class="fw-bold">สิทธิ์ปัจจุบันของ User</div><div class="small text-muted fw-normal">แสดงสิทธิ์ที่ได้จาก Role รวมกับสิทธิ์รายบุคคล และแก้ไข Override ได้จากตารางนี้</div></div>
      <input type="search" id="permissionUserTableSearch" class="form-control form-control-sm permission-user-search" placeholder="ค้นหารหัสพนักงาน หรือชื่อ User">
    </div>
    <div class="user-permission-table-wrap">
      <table class="table table-bordered table-hover user-permission-table align-middle">
        <thead><tr><th style="width:70px">ลำดับ</th><th style="width:230px">User</th><th style="width:220px">Role</th><th>สิทธิ์ที่ใช้งานอยู่ตอนนี้</th><th style="width:130px" class="text-center">จัดการ</th></tr></thead>
        <tbody>
        <?php foreach($users as $userIndex=>$u):
            $userKey=(string)($u['id']??($u['employee_code']??''));
            if($userKey==='') continue;
            $employeeCode=trim((string)($u['employee_code']??$userKey));
            $displayName=trim((string)($u['display_name']??'-'));
            $roleNames=[]; foreach($userRoleMap[$userKey]??[] as $rid){foreach($roles as $role){if((int)$role['id']===(int)$rid){$roleNames[]=(string)$role['role_name'];break;}}}
            $effectiveIds=$userEffectivePermissionMap[$userKey]??[];
            $overrideJson=json_encode($userPermissionOverrideMap[$userKey]??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $effectiveJson=json_encode(array_values(array_map('intval',$effectiveIds)),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        ?>
          <tr class="js-user-permission-row" data-search="<?php echo permE(mb_strtolower($employeeCode.' '.$displayName.' '.implode(' ',$roleNames),'UTF-8')); ?>">
            <td><?php echo number_format($userIndex+1); ?></td>
            <td><div class="fw-bold"><?php echo permE($displayName); ?></div><div class="small text-muted"><?php echo permE($employeeCode); ?></div></td>
            <td><?php echo $roleNames?permE(implode(', ',$roleNames)):'<span class="text-muted">ยังไม่มี Role</span>'; ?></td>
            <td><div class="user-permission-badges"><?php if(!$effectiveIds):?><span class="text-muted">ไม่มีสิทธิ์จากระบบกลาง</span><?php else: foreach($effectiveIds as $pid): $perm=$permissionById[(int)$pid]??null; if(!$perm)continue;?><span class="user-permission-badge" title="<?php echo permE($perm['permission_code']); ?>"><?php echo permE($perm['permission_name']); ?></span><?php endforeach; endif;?></div></td>
            <td class="text-center"><button type="button" class="btn btn-sm btn-primary js-manage-user-permissions" data-bs-toggle="modal" data-bs-target="#userPermissionModal" data-user-key="<?php echo permE($userKey); ?>" data-user-name="<?php echo permE($displayName); ?>" data-user-code="<?php echo permE($employeeCode); ?>" data-overrides="<?php echo permE($overrideJson); ?>" data-effective="<?php echo permE($effectiveJson); ?>">กำหนดสิทธิ์</button></td>
          </tr>
        <?php endforeach;?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade user-permission-modal" id="userPermissionModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><form method="post" class="modal-content">
    <div class="modal-header"><div><h5 class="modal-title fw-bold">กำหนดสิทธิ์ราย User</h5><div class="small text-muted" id="permissionModalUserText">-</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body bg-light"><input type="hidden" name="csrf_token" value="<?php echo permE($_SESSION['csrf_token']);?>"><input type="hidden" name="action" value="save_user_permissions"><input type="hidden" name="user_key" id="permissionModalUserKey" value="">
      <div class="alert alert-info py-2 small">เลือก <strong>ตาม Role</strong> เพื่อรับค่าจาก Role, <strong>อนุญาต</strong> เพื่อเพิ่มสิทธิ์เฉพาะ User หรือ <strong>ปฏิเสธ</strong> เพื่อตัดสิทธิ์ที่ได้จาก Role</div>
      <div class="user-permission-editor">
      <?php foreach($permissions as $perm):?>
        <div class="user-permission-editor-item" data-permission-id="<?php echo (int)$perm['id'];?>"><div class="fw-bold small"><?php echo permE($perm['permission_name']);?></div><div class="text-muted small mb-2"><?php echo permE($perm['permission_code']);?></div><select class="form-select form-select-sm js-user-permission-type" name="permission_type[<?php echo (int)$perm['id'];?>]"><option value="">ตาม Role</option><option value="allow">อนุญาต</option><option value="deny">ปฏิเสธ</option></select><div class="permission-effective-note mt-1 js-effective-note">-</div></div>
      <?php endforeach;?>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button><button class="btn btn-success px-4">บันทึกสิทธิ์ User</button></div>
  </form></div>
</div>


<div class="col-xl-4"><div class="card permission-card h-100"><div class="card-header">Role</div><div class="card-body">
<form method="post" class="row g-2 mb-3"><input type="hidden" name="csrf_token" value="<?php echo permE($_SESSION['csrf_token']);?>"><input type="hidden" name="action" value="save_role"><div class="col-5"><input name="role_code" class="form-control form-control-sm" placeholder="role_code" required></div><div class="col-7"><input name="role_name" class="form-control form-control-sm" placeholder="ชื่อ Role" required></div><div class="col-12"><input name="description" class="form-control form-control-sm" placeholder="รายละเอียด"></div><div class="col-12 d-grid"><button class="btn btn-primary btn-sm">เพิ่ม Role</button></div></form>
<?php foreach($roles as $role):?><div class="role-box mb-2"><div class="fw-bold"><?php echo permE($role['role_name']);?></div><div class="text-muted small"><?php echo permE($role['role_code']);?></div></div><?php endforeach;?>
</div></div></div>
<div class="col-xl-8"><div class="card permission-card"><div class="card-header">กำหนด Permission ให้ Role</div><div class="card-body">
<?php foreach($roles as $role):?><form method="post" class="border rounded-3 p-3 mb-3"><input type="hidden" name="csrf_token" value="<?php echo permE($_SESSION['csrf_token']);?>"><input type="hidden" name="action" value="save_role_permissions"><input type="hidden" name="role_id" value="<?php echo (int)$role['id'];?>"><div class="fw-bold mb-2"><?php echo permE($role['role_name']);?></div><div class="permission-grid"><?php $last=''; foreach($permissions as $perm):?><label class="permission-item small-check"><input type="checkbox" class="form-check-input me-1" name="permission_ids[]" value="<?php echo (int)$perm['id'];?>" <?php echo in_array((int)$perm['id'],$rolePermissionMap[(int)$role['id']]??[],true)?'checked':'';?>> <strong><?php echo permE($perm['permission_name']);?></strong><div class="text-muted small"><?php echo permE($perm['permission_code']);?></div></label><?php endforeach;?></div><div class="text-end mt-2"><button class="btn btn-success btn-sm">บันทึกสิทธิ์ Role</button></div></form><?php endforeach;?>
</div></div></div>
</div>
<script>(function(){
const select=document.getElementById('permissionUserSelect');
if(!select)return;
const checks=[...document.querySelectorAll('.user-role-check')];
const nameEl=document.getElementById('selectedUserName');
const codeEl=document.getElementById('selectedUserCode');
const countEl=document.getElementById('selectedRoleCount');

const syncCount=function(){
  const count=checks.filter(c=>c.checked).length;
  countEl.textContent=count+' Role';
  countEl.className='badge rounded-pill '+(count>0?'text-bg-primary':'text-bg-secondary');
};

const syncRoles=function(){
  const option=select.options[select.selectedIndex];
  const ids=(option?.dataset.roles||'').split(',').filter(Boolean);
  checks.forEach(c=>c.checked=ids.includes(c.value));

  if(select.value){
    nameEl.textContent=option?.dataset.name||option?.textContent||'-';
    codeEl.textContent='รหัสพนักงาน: '+(option?.dataset.employee||select.value);
  }else{
    nameEl.textContent='ยังไม่ได้เลือก User';
    codeEl.textContent='กรุณาเลือกจากรายการด้านบน';
  }
  syncCount();
};

select.addEventListener('change',syncRoles);
checks.forEach(c=>c.addEventListener('change',syncCount));
syncRoles();
})();
(function(){
const search=document.getElementById('permissionUserTableSearch');
const rows=[...document.querySelectorAll('.js-user-permission-row')];
if(search) search.addEventListener('input',function(){const q=String(search.value||'').toLocaleLowerCase('th-TH').trim();rows.forEach(row=>row.classList.toggle('d-none',q!==''&&!String(row.dataset.search||'').includes(q)));});
const modalKey=document.getElementById('permissionModalUserKey');
const modalText=document.getElementById('permissionModalUserText');
const selects=[...document.querySelectorAll('.js-user-permission-type')];
document.querySelectorAll('.js-manage-user-permissions').forEach(function(button){button.addEventListener('click',function(){let overrides={},effective=[];try{overrides=JSON.parse(button.dataset.overrides||'{}')||{};}catch(e){}try{effective=JSON.parse(button.dataset.effective||'[]')||[];}catch(e){}modalKey.value=button.dataset.userKey||'';modalText.textContent=(button.dataset.userCode||'-')+' - '+(button.dataset.userName||'-');selects.forEach(function(select){const id=String(select.closest('[data-permission-id]').dataset.permissionId||'');select.value=overrides[id]||'';const note=select.parentElement.querySelector('.js-effective-note');const active=effective.map(String).includes(id);note.textContent='สิทธิ์ปัจจุบัน: '+(active?'มีสิทธิ์':'ไม่มีสิทธิ์');note.className='permission-effective-note mt-1 js-effective-note '+(active?'text-success':'text-muted');});});});
})();</script>
<?php endif;?>
</div>

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

<?php require_once __DIR__ . '/../../../includes/footer.php';?>
