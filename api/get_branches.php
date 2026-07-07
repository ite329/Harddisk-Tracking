<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

function jsonResponse(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function isValidMainBranchCode(string $value): bool
{
    return preg_match('/^\d{3}$/', $value) === 1;
}

$inputBranchCode = trim((string)($_GET['branch_code'] ?? ''));

if ($inputBranchCode === '') {
    jsonResponse([
        'success' => false,
        'message' => 'กรุณากรอกรหัสสาขา',
        'total' => 0,
        'data' => []
    ]);
}

if (!isValidMainBranchCode($inputBranchCode)) {
    jsonResponse([
        'success' => false,
        'message' => 'กรุณากรอกรหัสสาขาเป็นตัวเลข 3 หลักเท่านั้น เช่น 088',
        'total' => 0,
        'data' => []
    ]);
}

try {
    /*
    |--------------------------------------------------------------------------
    | ค้นหาแบบรหัสสาขาตรงเท่านั้น
    |--------------------------------------------------------------------------
    | ต้องกรอกเป็นตัวเลข 3 หลักเท่านั้น เช่น 088
    | ไม่ค้นหาจากชื่อสาขา
    | ไม่ค้นหาจาก Cost Center
    */
    $sql = "
        SELECT
            main_branch_code,
            branch_code,
            branch_name,
            phone,
            full_address AS branch_address,
            landmark
        FROM branch_directory
        WHERE is_active = 1
          AND LPAD(main_branch_code, 3, '0') = :main_branch_code
        ORDER BY branch_code ASC
        LIMIT 100
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':main_branch_code' => $inputBranchCode
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        jsonResponse([
            'success' => false,
            'message' => 'ไม่พบข้อมูลสาขา กรุณาตรวจสอบรหัสสาขาให้ถูกต้อง',
            'total' => 0,
            'data' => []
        ]);
    }

    foreach ($rows as &$row) {
        $row['main_branch_code'] = str_pad((string)($row['main_branch_code'] ?? ''), 3, '0', STR_PAD_LEFT);
        $row['branch_code'] = (string)($row['branch_code'] ?? '');
        $row['branch_name'] = (string)($row['branch_name'] ?? '');
        $row['phone'] = (string)($row['phone'] ?? '');
        $row['branch_address'] = (string)($row['branch_address'] ?? '');
        $row['landmark'] = (string)($row['landmark'] ?? '');
    }
    unset($row);

    jsonResponse([
        'success' => true,
        'message' => 'ค้นหาข้อมูลสาขาสำเร็จ',
        'total' => count($rows),
        'data' => $rows
    ]);

} catch (PDOException $e) {
    jsonResponse([
        'success' => false,
        'message' => 'API Error: ' . $e->getMessage(),
        'total' => 0,
        'data' => []
    ]);
}