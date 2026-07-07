<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

if (PHP_SAPI !== 'cli') {
    require_role(['admin']);
}

$employeeCode = '100001';
$firstName = 'ผู้ดูแล';
$lastName = 'ระบบ';
$password = 'admin123';
$role = 'admin';

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$sql = "
    INSERT INTO users (
        employee_code,
        first_name,
        last_name,
        password_hash,
        role,
        is_active,
        created_at
    ) VALUES (
        :employee_code,
        :first_name,
        :last_name,
        :password_hash,
        :role,
        1,
        NOW()
    )
    ON DUPLICATE KEY UPDATE
        first_name = VALUES(first_name),
        last_name = VALUES(last_name),
        password_hash = VALUES(password_hash),
        role = VALUES(role),
        is_active = 1,
        updated_at = NOW()
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':employee_code' => $employeeCode,
    ':first_name' => $firstName,
    ':last_name' => $lastName,
    ':password_hash' => $passwordHash,
    ':role' => $role
]);

echo 'Create admin user success';
