<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'กรุณาเข้าสู่ระบบใหม่'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$serial = trim($_GET['serial'] ?? '');
if ($serial === '') { echo json_encode(['success'=>false,'message'=>'serial required']); exit; }
$stmt = $pdo->prepare("SELECT * FROM harddisk_inventory WHERE hdd_serial = :serial AND deleted_at IS NULL LIMIT 1");
$stmt->execute([':serial'=>$serial]);
$hdd = $stmt->fetch();
if (!$hdd) { echo json_encode(['success'=>false,'message'=>'ไม่พบ Serial HDD ในคลัง']); exit; }
if ($hdd['status'] !== 'available') { echo json_encode(['success'=>false,'message'=>'HDD ลูกนี้ไม่พร้อมใช้งาน','data'=>$hdd], JSON_UNESCAPED_UNICODE); exit; }
echo json_encode(['success'=>true,'data'=>$hdd], JSON_UNESCAPED_UNICODE);
