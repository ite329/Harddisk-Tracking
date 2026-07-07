<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';

$error = '';

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function getTableColumns(PDO $pdo, string $tableName): array
{
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $stmt->execute([
        ':table_name' => $tableName
    ]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function cleanValue($value): string
{
    return trim((string)($value ?? ''));
}

function isPasswordHashValue(string $hash): bool
{
    if ($hash === '') {
        return false;
    }

    $info = password_get_info($hash);

    return !empty($info['algo']);
}

function verifyUserPassword(string $inputPassword, array $user): array
{
    $passwordHash = cleanValue($user['password_hash'] ?? '');
    $plainPassword = cleanValue($user['password'] ?? '');

    /*
     * 1) กรณี password_hash เป็น Hash ที่สร้างจาก password_hash()
     */
    if ($passwordHash !== '' && isPasswordHashValue($passwordHash)) {
        if (password_verify($inputPassword, $passwordHash)) {
            return [
                'success' => true,
                'need_rehash' => password_needs_rehash($passwordHash, PASSWORD_DEFAULT),
                'source' => 'password_hash'
            ];
        }

        return [
            'success' => false,
            'need_rehash' => false,
            'source' => 'password_hash'
        ];
    }

    /*
     * 2) กรณี password_hash เก็บเป็นรหัสผ่านธรรมดา เช่น 1234
     * ใช้เพื่อแก้ปัญหา User เดิม Login ไม่ได้
     * หลัง Login สำเร็จ ระบบจะเข้ารหัสใหม่ให้อัตโนมัติ
     */
    if ($passwordHash !== '' && hash_equals($passwordHash, $inputPassword)) {
        return [
            'success' => true,
            'need_rehash' => true,
            'source' => 'plain_in_password_hash'
        ];
    }

    /*
     * 3) กรณีตารางมีคอลัมน์ password และเก็บเป็นรหัสผ่านธรรมดา
     */
    if ($plainPassword !== '' && hash_equals($plainPassword, $inputPassword)) {
        return [
            'success' => true,
            'need_rehash' => true,
            'source' => 'plain_password'
        ];
    }

    /*
     * 4) รองรับกรณีเคยเก็บแบบ md5 หรือ sha1
     */
    if ($passwordHash !== '' && strlen($passwordHash) === 32 && hash_equals($passwordHash, md5($inputPassword))) {
        return [
            'success' => true,
            'need_rehash' => true,
            'source' => 'md5'
        ];
    }

    if ($passwordHash !== '' && strlen($passwordHash) === 40 && hash_equals($passwordHash, sha1($inputPassword))) {
        return [
            'success' => true,
            'need_rehash' => true,
            'source' => 'sha1'
        ];
    }

    return [
        'success' => false,
        'need_rehash' => false,
        'source' => 'not_match'
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $employeeCode = cleanValue($_POST['employee_code'] ?? '');
    $password = cleanValue($_POST['password'] ?? '');

    if ($employeeCode === '' || $password === '') {
        $error = 'กรุณากรอกรหัสพนักงานและรหัสผ่าน';
    } else {
        try {
            $userColumns = getTableColumns($pdo, 'users');

            $selectColumns = [];

            foreach ([
                'id',
                'employee_code',
                'first_name',
                'last_name',
                'password_hash',
                'password',
                'role',
                'is_active',
                'deleted_at'
            ] as $column) {
                if (hasColumn($userColumns, $column)) {
                    $selectColumns[] = $column;
                }
            }

            if (empty($selectColumns)) {
                throw new Exception('ไม่พบคอลัมน์ที่จำเป็นในตาราง users');
            }

            if (!hasColumn($userColumns, 'employee_code')) {
                throw new Exception('ตาราง users ไม่มีคอลัมน์ employee_code');
            }

            $where = [
                'employee_code = :employee_code'
            ];

            if (hasColumn($userColumns, 'deleted_at')) {
                $where[] = 'deleted_at IS NULL';
            }

            $sql = "
                SELECT " . implode(', ', $selectColumns) . "
                FROM users
                WHERE " . implode(' AND ', $where) . "
                LIMIT 1
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':employee_code' => $employeeCode
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $error = 'ไม่พบรหัสพนักงานนี้ในระบบ';
            } elseif (hasColumn($userColumns, 'is_active') && (int)($user['is_active'] ?? 0) !== 1) {
                $error = 'บัญชีผู้ใช้งานนี้ถูกปิดใช้งาน';
            } else {
                $verifyResult = verifyUserPassword($password, $user);

                if (!$verifyResult['success']) {
                    $error = 'รหัสผ่านไม่ถูกต้อง';
                } else {
                    /*
                     * ถ้า User เดิมเก็บ Password แบบ plain/md5/sha1
                     * เมื่อ Login สำเร็จ จะเปลี่ยนเป็น password_hash() ให้อัตโนมัติ
                     */
                    if (
                        hasColumn($userColumns, 'password_hash') &&
                        $verifyResult['need_rehash'] === true
                    ) {
                        $newPasswordHash = password_hash($password, PASSWORD_DEFAULT);

                        $updateHashSql = "
                            UPDATE users
                            SET password_hash = :password_hash
                            WHERE id = :id
                        ";

                        $updateHashStmt = $pdo->prepare($updateHashSql);
                        $updateHashStmt->execute([
                            ':password_hash' => $newPasswordHash,
                            ':id' => $user['id']
                        ]);
                    }

                    session_regenerate_id(true);

                    $_SESSION['user_id'] = $user['id'] ?? '';
                    $_SESSION['employee_code'] = $user['employee_code'] ?? '';
                    $_SESSION['first_name'] = $user['first_name'] ?? '';
                    $_SESSION['last_name'] = $user['last_name'] ?? '';
                    $_SESSION['full_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                    $_SESSION['role'] = $user['role'] ?? 'user';

                    if (hasColumn($userColumns, 'last_login_at')) {
                        $updateSql = "
                            UPDATE users
                            SET last_login_at = NOW()
                            WHERE id = :id
                        ";

                        $updateStmt = $pdo->prepare($updateSql);
                        $updateStmt->execute([
                            ':id' => $user['id']
                        ]);
                    }

                    header('Location: index.php');
                    exit;
                }
            }
        } catch (Throwable $e) {
            $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Login | Harddisk Tracking System</title>

    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/app.css" rel="stylesheet">
</head>
<body class="login-page">

<div class="container">
    <div class="row justify-content-center align-items-center min-vh-100">
        <div class="col-md-5 col-lg-4">

            <div class="card shadow-sm border-0 login-card">
                <div class="card-body p-4">

                    <div class="text-center mb-4">
                        <h4 class="fw-bold mb-1">Harddisk Tracking System</h4>
                        <div class="text-muted">ระบบจัดส่ง Harddisk ให้สาขา</div>
                    </div>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger">
                            <?php echo h($error); ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" autocomplete="off">
                        <?php echo csrf_field(); ?>

                        <div class="mb-3">
                            <label class="form-label">รหัสพนักงาน</label>
                            <input type="text"
                                   name="employee_code"
                                   class="form-control"
                                   placeholder="กรอกรหัสพนักงาน"
                                   value="<?php echo h($_POST['employee_code'] ?? ''); ?>"
                                   required
                                   autofocus>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">รหัสผ่าน</label>
                            <input type="password"
                                   name="password"
                                   class="form-control"
                                   placeholder="1234"
                                   required>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            เข้าสู่ระบบ
                        </button>
                    </form>

                </div>
            </div>

        </div>
    </div>
</div>

</body>
</html>