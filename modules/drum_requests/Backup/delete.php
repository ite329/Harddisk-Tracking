<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
if (function_exists('require_login')) require_login();

function drumDeleteHasPermission(PDO $pdo): bool
{
    if (function_exists('is_super_admin_employee') && is_super_admin_employee()) return true;
    if (function_exists('current_user_role') && current_user_role() === 'super_admin') return true;
    if (!function_exists('permission_tables_ready') || !permission_tables_ready($pdo) || !function_exists('central_permission_user_key')) return false;
    $userKey=trim((string)central_permission_user_key()); if($userKey==='')return false;
    try{
        $stmt=$pdo->prepare("SELECT id FROM permissions WHERE permission_code='drum_requests.delete' AND is_active=1 LIMIT 1");$stmt->execute();$id=(int)$stmt->fetchColumn();if($id<=0)return false;
        $stmt=$pdo->prepare("SELECT permission_type FROM user_permissions WHERE user_key=:u AND permission_id=:p LIMIT 1");$stmt->execute([':u'=>$userKey,':p'=>$id]);$override=trim((string)($stmt->fetchColumn()?:''));if($override==='deny')return false;if($override==='allow')return true;
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id AND r.is_active=1 INNER JOIN role_permissions rp ON rp.role_id=ur.role_id WHERE ur.user_key=:u AND rp.permission_id=:p");$stmt->execute([':u'=>$userKey,':p'=>$id]);return (int)$stmt->fetchColumn()>0;
    }catch(Throwable $e){error_log('[drum_withdrawals/delete] Permission check failed: '.$e->getMessage());return false;}
}

if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: index.php');exit;}
if(!drumDeleteHasPermission($pdo)){http_response_code(403);$_SESSION['drum_error']='ไม่มีสิทธิ์ลบรายการเบิก Drum';header('Location: index.php');exit;}
if(empty($_SESSION['csrf_drum'])||!hash_equals((string)$_SESSION['csrf_drum'],(string)($_POST['csrf_token']??''))){$_SESSION['drum_error']='Session หมดอายุ กรุณาลองใหม่';header('Location: index.php');exit;}
$requestNo=trim((string)($_POST['request_no']??''));if($requestNo===''){$_SESSION['drum_error']='ไม่พบเลขที่รายการที่ต้องการลบ';header('Location: index.php');exit;}
try{
    $columnsStmt=$pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='harddisk_db' AND TABLE_NAME='drum_withdrawals'");$columnsStmt->execute();$columns=array_map('strtolower',$columnsStmt->fetchAll(PDO::FETCH_COLUMN));
    if(in_array('deleted_at',$columns,true)){$stmt=$pdo->prepare("UPDATE harddisk_db.drum_withdrawals SET deleted_at=NOW() WHERE request_no=:request_no AND deleted_at IS NULL");}else{$stmt=$pdo->prepare("DELETE FROM harddisk_db.drum_withdrawals WHERE request_no=:request_no");}
    $stmt->execute([':request_no'=>$requestNo]);
    if($stmt->rowCount()<=0)throw new RuntimeException('ไม่พบรายการที่ต้องการลบ');
    $_SESSION['drum_success']='ลบรายการเบิก Drum เลขที่ '.$requestNo.' เรียบร้อยแล้ว';
}catch(Throwable $e){error_log('[drum_withdrawals/delete] '.$e->getMessage());$_SESSION['drum_error']=$e instanceof RuntimeException?$e->getMessage():'ไม่สามารถลบข้อมูลได้';}
header('Location: index.php');
