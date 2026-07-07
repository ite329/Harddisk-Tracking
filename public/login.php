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
    $stmt = $pdo->prepare("\n        SELECT COLUMN_NAME\n        FROM INFORMATION_SCHEMA.COLUMNS\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = :table_name\n    ");
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

    if ($passwordHash !== '' && hash_equals($passwordHash, $inputPassword)) {
        return [
            'success' => true,
            'need_rehash' => true,
            'source' => 'plain_in_password_hash'
        ];
    }

    if ($plainPassword !== '' && hash_equals($plainPassword, $inputPassword)) {
        return [
            'success' => true,
            'need_rehash' => true,
            'source' => 'plain_password'
        ];
    }

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
                'deleted_at',
                'last_login_at'
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

            $sql = "\n                SELECT " . implode(', ', $selectColumns) . "\n                FROM users\n                WHERE " . implode(' AND ', $where) . "\n                LIMIT 1\n            ";

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
                    if (hasColumn($userColumns, 'password_hash') && $verifyResult['need_rehash'] === true) {
                        $newPasswordHash = password_hash($password, PASSWORD_DEFAULT);

                        $updateHashSql = "\n                            UPDATE users\n                            SET password_hash = :password_hash\n                            WHERE id = :id\n                        ";

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
                        $updateSql = "\n                            UPDATE users\n                            SET last_login_at = NOW()\n                            WHERE id = :id\n                        ";

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Harddisk Tracking System</title>

    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/app.css" rel="stylesheet">

    <style>
        :root {
            --brand-1: #0ea5e9;
            --brand-2: #2563eb;
            --brand-3: #0f172a;
            --soft-bg: #eef4ff;
            --card-border: rgba(148, 163, 184, 0.18);
            --text-muted: #64748b;
        }

        * {
            box-sizing: border-box;
        }

        body.login-redesign {
            min-height: 100vh;
            margin: 0;
            background:
                radial-gradient(circle at top left, rgba(14, 165, 233, 0.18), transparent 30%),
                radial-gradient(circle at bottom right, rgba(37, 99, 235, 0.15), transparent 28%),
                linear-gradient(135deg, #f8fbff 0%, #eef4ff 50%, #f8fafc 100%);
            color: #0f172a;
        }

        .login-shell {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px 16px;
        }

        .login-board {
            width: 100%;
            max-width: 1180px;
            border-radius: 28px;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.86);
            backdrop-filter: blur(10px);
            box-shadow: 0 24px 70px rgba(15, 23, 42, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.65);
        }

        .login-brand-panel {
            position: relative;
            padding: 42px 38px;
            min-height: 100%;
            background: linear-gradient(145deg, #0ea5e9 0%, #2563eb 58%, #1e3a8a 100%);
            color: #fff;
            overflow: hidden;
        }

        .login-brand-panel::before,
        .login-brand-panel::after {
            content: "";
            position: absolute;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.09);
            pointer-events: none;
        }

        .login-brand-panel::before {
            width: 240px;
            height: 240px;
            top: -80px;
            right: -70px;
        }

        .login-brand-panel::after {
            width: 200px;
            height: 200px;
            left: -60px;
            bottom: -70px;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.16);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 999px;
            padding: 9px 16px;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .brand-title {
            font-size: 34px;
            line-height: 1.18;
            font-weight: 800;
            margin-bottom: 12px;
            letter-spacing: -0.02em;
            color: #ffffff !important;
        }

        .brand-subtitle {
            font-size: 16px;
            line-height: 1.7;
            opacity: 0.96;
            max-width: 520px;
            margin-bottom: 24px;
            color: #ffffff !important;
        }

        .brand-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin-top: 24px;
        }

        .brand-stat {
            position: relative;
            z-index: 1;
            padding: 16px 18px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 18px;
        }

        .brand-stat .emoji {
            font-size: 21px;
            margin-bottom: 10px;
        }

        .brand-stat-title {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .brand-stat-desc {
            font-size: 13px;
            line-height: 1.55;
            opacity: 0.93;
        }

        .brand-footer-note {
            position: relative;
            z-index: 1;
            margin-top: 24px;
            font-size: 13px;
            line-height: 1.6;
            color: rgba(255, 255, 255, 0.9);
        }

        .login-brand-panel .brand-badge,
        .login-brand-panel .brand-title,
        .login-brand-panel .brand-subtitle,
        .login-brand-panel .brand-stat-title,
        .login-brand-panel .brand-stat-desc,
        .login-brand-panel .brand-footer-note {
            color: #ffffff !important;
        }

        .login-form-panel {
            padding: 38px 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(180deg, rgba(255,255,255,0.92), rgba(255,255,255,0.98));
        }

        .login-form-wrap {
            width: 100%;
            max-width: 430px;
        }

        .login-chip-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .login-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #dbeafe;
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 700;
        }

        .login-date {
            font-size: 12px;
            color: var(--text-muted);
            white-space: nowrap;
        }

        .form-card {
            border-radius: 24px;
            border: 1px solid var(--card-border);
            background: #ffffff;
            box-shadow: 0 16px 45px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }

        .form-card-body {
            padding: 28px;
        }

        .login-title {
            font-size: 30px;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #0f172a;
            margin-bottom: 6px;
        }

        .login-subtitle {
            color: var(--text-muted);
            line-height: 1.7;
            font-size: 14px;
            margin-bottom: 22px;
        }

        .alert-soft-danger {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            border-radius: 16px;
            padding: 14px 16px;
            font-size: 14px;
        }

        .form-section-title {
            font-size: 13px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: 0.02em;
            margin-bottom: 10px;
        }

        .form-label {
            font-size: 14px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 8px;
        }

        .input-shell {
            position: relative;
        }

        .input-shell .form-control {
            height: 52px;
            border-radius: 16px;
            border: 1px solid #dbe1ea;
            background: #f8fbff;
            padding-left: 44px;
            padding-right: 44px;
            font-size: 15px;
            transition: all 0.2s ease;
            box-shadow: none;
        }

        .input-shell .form-control:focus {
            background: #fff;
            border-color: #60a5fa;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.13);
        }

        .input-icon,
        .toggle-password-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 16px;
            line-height: 1;
        }

        .input-icon {
            left: 15px;
        }

        .toggle-password-btn {
            right: 12px;
            border: 0;
            background: transparent;
            padding: 4px;
            cursor: pointer;
        }

        .toggle-password-btn:hover {
            color: #1d4ed8;
        }

        .helper-card {
            border-radius: 18px;
            background: var(--soft-bg);
            border: 1px dashed #bfdbfe;
            padding: 14px 16px;
            margin-top: 18px;
        }

        .helper-card-title {
            font-size: 13px;
            font-weight: 800;
            color: #1e3a8a;
            margin-bottom: 8px;
        }

        .helper-list {
            padding-left: 18px;
            margin: 0;
            color: #334155;
            font-size: 13px;
            line-height: 1.7;
        }

        .btn-login {
            height: 52px;
            border-radius: 16px;
            border: 0;
            background: linear-gradient(135deg, var(--brand-1), var(--brand-2));
            font-size: 15px;
            font-weight: 800;
            letter-spacing: 0.01em;
            box-shadow: 0 14px 24px rgba(37, 99, 235, 0.22);
        }

        .btn-login:hover,
        .btn-login:focus {
            background: linear-gradient(135deg, #0284c7, #1d4ed8);
            box-shadow: 0 16px 28px rgba(37, 99, 235, 0.28);
        }

        .security-note {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 16px;
            font-size: 12px;
            color: #64748b;
        }

        .copyright-text {
            text-align: center;
            margin-top: 14px;
            font-size: 12px;
            color: #94a3b8;
        }

        @media (max-width: 991.98px) {
            .login-brand-panel {
                padding: 30px 24px;
            }

            .login-form-panel {
                padding: 24px 16px;
            }

            .brand-title {
                font-size: 28px;
            }

            .form-card-body {
                padding: 22px 18px;
            }
        }

        @media (max-width: 767.98px) {
            .login-shell {
                padding: 14px;
            }

            .login-board {
                border-radius: 20px;
            }

            .brand-grid {
                grid-template-columns: 1fr;
            }

            .login-chip-row {
                flex-direction: column;
                align-items: flex-start;
            }

            .login-title {
                font-size: 26px;
            }
        }
    </style>
</head>
<body class="login-redesign">
<div class="login-shell">
    <div class="login-board">
        <div class="row g-0">
            <div class="col-lg-6">
                <div class="login-brand-panel h-100 d-flex flex-column justify-content-between">
                    <div>
                        <div class="brand-badge">
                            <span>💽</span>
                            <span>Harddisk Tracking System</span>
                        </div>

                        <div class="brand-title">จัดการคำขอส่ง HDD และติดตามสถานะได้ในที่เดียว</div>
                        <div class="brand-subtitle">
                            ระบบสำหรับบันทึกคำขอส่ง Harddisk, จับคู่ Serial HDD, ติดตามการจัดส่ง,
                            รับคืนส่งเคลม และบริหารคลัง HDD ให้ทำงานได้ง่ายขึ้นอย่างเป็นระบบ
                        </div>

                        <div class="brand-grid">
                            <div class="brand-stat">
                                <div class="emoji">📝</div>
                                <div class="brand-stat-title">บันทึกคำขอได้รวดเร็ว</div>
                                <div class="brand-stat-desc">ค้นหารหัสสาขาใหญ่และดึงรายชื่อสาขาในสังกัดได้ทันที</div>
                            </div>
                            <div class="brand-stat">
                                <div class="emoji">📦</div>
                                <div class="brand-stat-title">ติดตามการจัดส่งชัดเจน</div>
                                <div class="brand-stat-desc">ตรวจสอบสถานะตั้งแต่รอยิงบาร์โค้ดจนถึงจัดส่งสำเร็จ</div>
                            </div>
                            <div class="brand-stat">
                                <div class="emoji">🔧</div>
                                <div class="brand-stat-title">บริหารคลัง HDD ง่าย</div>
                                <div class="brand-stat-desc">รับเข้า จองใช้งาน ตรวจสอบ Serial และสถานะคลังในหน้าเดียว</div>
                            </div>
                            <div class="brand-stat">
                                <div class="emoji">🧸</div>
                                <div class="brand-stat-title">รองรับงานเคลม</div>
                                <div class="brand-stat-desc">รับคืน HDD จากสาขาและติดตามการส่งเคลมได้อย่างเป็นระเบียบ</div>
                            </div>
                        </div>
                    </div>

                    <div class="brand-footer-note">
                        ใช้งานด้วยรหัสพนักงานและรหัสผ่านของคุณ เพื่อเข้าสู่ระบบจัดส่ง Harddisk อย่างปลอดภัย
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="login-form-panel h-100">
                    <div class="login-form-wrap">
                        <div class="login-chip-row">
                            <div class="login-chip">
                                <span>🔐</span>
                                <span>Secure Login</span>
                            </div>
                            <div class="login-date"><?php echo h(date('d/m/Y H:i')); ?></div>
                        </div>

                        <div class="form-card">
                            <div class="form-card-body">
                                <div class="login-title">เข้าสู่ระบบ</div>
                                <div class="login-subtitle">
                                    กรุณากรอกรหัสพนักงานและรหัสผ่านเพื่อใช้งานระบบจัดส่ง Harddisk
                                </div>

                                <?php if ($error !== ''): ?>
                                    <div class="alert-soft-danger mb-3">
                                        <strong>ไม่สามารถเข้าสู่ระบบได้:</strong>
                                        <?php echo h($error); ?>
                                    </div>
                                <?php endif; ?>

                                <form method="post" autocomplete="off">
                                    <?php echo csrf_field(); ?>

                                    <div class="form-section-title">ข้อมูลผู้ใช้งาน</div>

                                    <div class="mb-3">
                                        <label class="form-label" for="employee_code">รหัสพนักงาน</label>
                                        <div class="input-shell">
                                            <span class="input-icon">👤</span>
                                            <input type="text"
                                                   id="employee_code"
                                                   name="employee_code"
                                                   class="form-control"
                                                   placeholder="กรอกรหัสพนักงาน"
                                                   value="<?php echo h($_POST['employee_code'] ?? ''); ?>"
                                                   required
                                                   autofocus>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label" for="password">รหัสผ่าน</label>
                                        <div class="input-shell">
                                            <span class="input-icon">🔑</span>
                                            <input type="password"
                                                   id="password"
                                                   name="password"
                                                   class="form-control"
                                                   placeholder="กรอกรหัสผ่าน"
                                                   required>
                                            <button type="button" class="toggle-password-btn" id="togglePassword" aria-label="แสดงหรือซ่อนรหัสผ่าน">👁️</button>
                                        </div>
                                    </div>

                                    <div class="d-grid mt-4">
                                        <button type="submit" class="btn btn-primary btn-login">
                                            เข้าสู่ระบบ 🚀
                                        </button>
                                    </div>
                                </form>

                                <div class="helper-card">
                                    <div class="helper-card-title">คำแนะนำการใช้งาน</div>
                                    <ul class="helper-list">
                                        <li>&quot;รหัสพนักงาน 5 หลัก&quot; ตัวอย่างเช่น 10001</li>
                                        <li>กรอกรหัสพนักงานให้ตรงกับข้อมูลในระบบ</li>
                                        <li>หากเข้าสู่ระบบไม่ได้ ให้ตรวจสอบรหัสผ่านอีกครั้ง</li>
                                        <li>หากยังพบปัญหา ให้ติดต่อผู้ดูแลระบบ IT</li>
                                    </ul>
                                </div>

                                <div class="security-note">
                                    <span>🛡️</span>
                                    <span>ข้อมูลการเข้าสู่ระบบของคุณจะถูกตรวจสอบอย่างปลอดภัย</span>
                                </div>
                            </div>
                        </div>

                        <div class="copyright-text">
                            © <?php echo h(date('Y')); ?> Harddisk Tracking System
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        var togglePasswordBtn = document.getElementById('togglePassword');
        var passwordInput = document.getElementById('password');

        if (togglePasswordBtn && passwordInput) {
            togglePasswordBtn.addEventListener('click', function () {
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    togglePasswordBtn.textContent = '🙈';
                } else {
                    passwordInput.type = 'password';
                    togglePasswordBtn.textContent = '👁️';
                }
            });
        }
    })();
</script>
</body>
</html>
