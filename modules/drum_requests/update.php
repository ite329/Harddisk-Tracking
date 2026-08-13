<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

error_log('[drum_withdrawals/update] start PHP=' . PHP_VERSION . ' request_method=' . (string)($_SERVER['REQUEST_METHOD'] ?? ''));

register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error) return;
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (in_array((int)$error['type'], $fatalTypes, true)) {
        error_log('[drum_withdrawals/update] FATAL ' . ($error['message'] ?? '') . ' in ' . ($error['file'] ?? '') . ':' . ($error['line'] ?? ''));
    }
});

function drumUpdateTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function drumUpdateTextCut(string $value, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
}

if (function_exists('require_login')) require_login();

function drumManageHasPermission(PDO $pdo, string $permissionCode): bool
{
    try {
        if (function_exists('is_super_admin_employee') && is_super_admin_employee()) return true;
        if (function_exists('current_user_role') && current_user_role() === 'super_admin') return true;
        if (!function_exists('permission_tables_ready') || !permission_tables_ready($pdo) || !function_exists('central_permission_user_key')) return false;
        $userKey = trim((string)central_permission_user_key());
        if ($userKey === '') return false;
        $stmt = $pdo->prepare("SELECT id FROM permissions WHERE permission_code=:code AND is_active=1 LIMIT 1");
        $stmt->execute([':code'=>$permissionCode]);
        $permissionId=(int)$stmt->fetchColumn();
        if ($permissionId<=0) return false;
        $stmt=$pdo->prepare("SELECT permission_type FROM user_permissions WHERE user_key=:user_key AND permission_id=:permission_id LIMIT 1");
        $stmt->execute([':user_key'=>$userKey,':permission_id'=>$permissionId]);
        $override=trim((string)($stmt->fetchColumn()?:''));
        if ($override==='deny') return false;
        if ($override==='allow') return true;
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id AND r.is_active=1 INNER JOIN role_permissions rp ON rp.role_id=ur.role_id WHERE ur.user_key=:user_key AND rp.permission_id=:permission_id");
        $stmt->execute([':user_key'=>$userKey,':permission_id'=>$permissionId]);
        return (int)$stmt->fetchColumn()>0;
    } catch (Throwable $e) {
        error_log('[drum_withdrawals/update] Permission check failed: '.$e->getMessage());
        return false;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: index.php'); exit; }
try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('ไม่พบการเชื่อมต่อฐานข้อมูล');
    }
    if (!drumManageHasPermission($pdo, 'drum_requests.edit')) {
        $_SESSION['drum_error']='ไม่มีสิทธิ์แก้ไขรายการเบิก Drum';
        header('Location: index.php');
        exit;
    }
    if (empty($_SESSION['csrf_drum']) || !hash_equals((string)$_SESSION['csrf_drum'], (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['drum_error']='Session หมดอายุ กรุณาลองใหม่';
        header('Location: index.php');
        exit;
    }
} catch (Throwable $accessError) {
    error_log('[drum_withdrawals/update] Access/permission error: ' . $accessError->getMessage());
    $_SESSION['drum_error']='ไม่สามารถตรวจสอบสิทธิ์การแก้ไขได้';
    header('Location: index.php');
    exit;
}

$requestNo=trim((string)($_POST['request_no']??''));
$mainBranchCode=preg_replace('/\D+/', '', trim((string)($_POST['main_branch_code']??'')));
$mainBranchCode=$mainBranchCode!==''?str_pad(substr($mainBranchCode,0,3),3,'0',STR_PAD_LEFT):'';
$branchCode=trim((string)($_POST['branch_code']??''));
$recordedBy=trim((string)($_POST['recorded_by']??''));
$createdAtInput=trim((string)($_POST['created_at']??''));
$problemNo=trim((string)($_POST['problem_no']??''));
$remark=trim((string)($_POST['remark']??''));
if (drumUpdateTextLength($problemNo) > 100) $problemNo = drumUpdateTextCut($problemNo, 100);
if (drumUpdateTextLength($remark) > 500) $remark = drumUpdateTextCut($remark, 500);
$drums=is_array($_POST['drum_codes']??null)?$_POST['drum_codes']:[];
$drums=array_values(array_filter(array_map('trim',$drums),static function($drum){
    return in_array($drum,['Drum-DR-3455','Drum-DR-3608'],true);
}));
$uniqueDrums=array_values(array_unique($drums));
if ($requestNo===''||$mainBranchCode===''||$branchCode===''||$recordedBy===''||$createdAtInput===''||$problemNo===''||!$drums) { $_SESSION['drum_error']='กรุณากรอกข้อมูลแก้ไขให้ครบถ้วน'; header('Location: index.php'); exit; }
try {
    $createdAt=DateTime::createFromFormat('Y-m-d\TH:i',$createdAtInput,new DateTimeZone('Asia/Bangkok'));
    if (!$createdAt) {
        throw new RuntimeException('รูปแบบวันที่บันทึกไม่ถูกต้อง');
    }

    $branchStmt=$pdo->prepare("SELECT branch_name,branch_name_2 FROM harddisk_db.branch_directory WHERE LPAD(TRIM(CAST(main_branch_code AS CHAR)),3,'0')=:main_code AND TRIM(CAST(branch_code AS CHAR))=:branch_code LIMIT 1");
    $branchStmt->execute([':main_code'=>$mainBranchCode,':branch_code'=>$branchCode]);
    $branch=$branchStmt->fetch(PDO::FETCH_ASSOC);
    if (!$branch) throw new RuntimeException('ไม่พบ Cost Center ภายใต้รหัสสาขาใหญ่ที่ระบุ');
    $verifiedName=trim((string)($branch['branch_name']??'')) ?: trim((string)($branch['branch_name_2']??''));
    if ($verifiedName==='') throw new RuntimeException('ไม่พบชื่อสาขาจากข้อมูลสาขากลาง');

    $columnsStmt=$pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='harddisk_db' AND TABLE_NAME='drum_withdrawals'");
    $columnsStmt->execute();
    $columns=array_map('strtolower',$columnsStmt->fetchAll(PDO::FETCH_COLUMN));
    $hasBranchCode=in_array('branch_code',$columns,true);
    $hasEmployeeCode=in_array('recorded_by_employee_code',$columns,true);
    $hasProblemNo=in_array('problem_no',$columns,true);
    $hasRemark=in_array('remark',$columns,true);
    $hasQuantity=in_array('quantity',$columns,true);
    if (!$hasProblemNo || !$hasRemark) throw new RuntimeException('ตาราง drum_withdrawals ยังไม่มีคอลัมน์ problem_no หรือ remark กรุณารันไฟล์ database/add_drum_problem_no_remark.sql');
    if (!$hasQuantity) throw new RuntimeException('ตาราง drum_withdrawals ยังไม่มีคอลัมน์ quantity กรุณารันไฟล์ database/add_drum_quantity.sql');

    $existingStmt=$pdo->prepare("SELECT * FROM harddisk_db.drum_withdrawals WHERE request_no=:request_no LIMIT 1");
    $existingStmt->execute([':request_no'=>$requestNo]);
    $existing=$existingStmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) throw new RuntimeException('ไม่พบรายการที่ต้องการแก้ไข');
    $employeeCode=$hasEmployeeCode?trim((string)($existing['recorded_by_employee_code']??'')):'';

    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM harddisk_db.drum_withdrawals WHERE request_no=:request_no")->execute([':request_no'=>$requestNo]);
    $insertColumns=['request_no','main_branch_code'];
    $insertValues=[':request_no',':main_branch_code'];
    if ($hasBranchCode) { $insertColumns[]='branch_code'; $insertValues[]=':branch_code'; }
    $insertColumns=array_merge($insertColumns,['branch_name','drum_code','quantity','recorded_by','problem_no','remark']);
    $insertValues=array_merge($insertValues,[':branch_name',':drum_code',':quantity',':recorded_by',':problem_no',':remark']);
    if ($hasEmployeeCode) { $insertColumns[]='recorded_by_employee_code'; $insertValues[]=':employee_code'; }
    $insertColumns[]='created_at'; $insertValues[]=':created_at';
    $insert=$pdo->prepare('INSERT INTO harddisk_db.drum_withdrawals ('.implode(',',$insertColumns).') VALUES ('.implode(',',$insertValues).')');
    foreach($uniqueDrums as $drum){
        $params=[':request_no'=>$requestNo,':main_branch_code'=>$mainBranchCode,':branch_name'=>$verifiedName,':drum_code'=>$drum,':quantity'=>1,':recorded_by'=>$recordedBy,':problem_no'=>$problemNo,':remark'=>$remark!==''?$remark:null,':created_at'=>$createdAt->format('Y-m-d H:i:s')];
        if($hasBranchCode)$params[':branch_code']=$branchCode;
        if($hasEmployeeCode)$params[':employee_code']=$employeeCode!==''?$employeeCode:null;
        $insert->execute($params);
    }
    $pdo->commit();
    $_SESSION['drum_success']='แก้ไขรายการเบิก Drum เลขที่ '.$requestNo.' เรียบร้อยแล้ว';
} catch(Throwable $e){
    if(isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction())$pdo->rollBack();
    error_log('[drum_withdrawals/update] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $_SESSION['drum_error']=$e instanceof RuntimeException?$e->getMessage():'ไม่สามารถแก้ไขข้อมูลได้';
}
header('Location: index.php');
exit;
