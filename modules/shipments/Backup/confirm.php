<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_login();

$pageTitle = 'รอยืนยันจัดส่ง HDD';

/*
|--------------------------------------------------------------------------
| ดึงชื่อผู้ Login จาก users table
|--------------------------------------------------------------------------
| สำคัญ:
| ใช้ชื่อนี้ไปเทียบกับ matched_by แบบตรงตัว
|--------------------------------------------------------------------------
*/
function getCurrentUserFullNameFromDatabase($pdo)
{
    $userId = null;
    $employeeCode = null;

    if (!empty($_SESSION['user_id'])) {
        $userId = (int)$_SESSION['user_id'];
    } elseif (!empty($_SESSION['id'])) {
        $userId = (int)$_SESSION['id'];
    } elseif (!empty($_SESSION['user']['id'])) {
        $userId = (int)$_SESSION['user']['id'];
    }

    if (!empty($_SESSION['employee_code'])) {
        $employeeCode = trim($_SESSION['employee_code']);
    } elseif (!empty($_SESSION['user']['employee_code'])) {
        $employeeCode = trim($_SESSION['user']['employee_code']);
    }

    if ($userId > 0) {
        $sql = "
            SELECT 
                employee_code,
                first_name,
                last_name
            FROM users
            WHERE id = :id
              AND deleted_at IS NULL
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id' => $userId
        ]);

        $user = $stmt->fetch();

        if ($user) {
            return trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        }
    }

    if ($employeeCode !== '') {
        $sql = "
            SELECT 
                employee_code,
                first_name,
                last_name
            FROM users
            WHERE employee_code = :employee_code
              AND deleted_at IS NULL
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':employee_code' => $employeeCode
        ]);

        $user = $stmt->fetch();

        if ($user) {
            return trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        }
    }

    if (!empty($_SESSION['first_name']) || !empty($_SESSION['last_name'])) {
        return trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    }

    if (!empty($_SESSION['user']['first_name']) || !empty($_SESSION['user']['last_name'])) {
        return trim(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? ''));
    }

    return '';
}

function formatDateTimeThaiConfirm($value)
{
    if (empty($value)) {
        return '-';
    }

    $timestamp = strtotime($value);

    if (!$timestamp) {
        return '-';
    }

    return date('d/m/Y H:i', $timestamp);
}

function formatBranchCodeConfirm($branchCode)
{
    $branchCode = trim((string)$branchCode);

    if ($branchCode === '') {
        return '-';
    }

    if (ctype_digit($branchCode)) {
        return str_pad($branchCode, 3, '0', STR_PAD_LEFT);
    }

    return $branchCode;
}

$currentUserName = getCurrentUserFullNameFromDatabase($pdo);

if ($currentUserName === '') {
    die('ไม่พบชื่อผู้ Login กรุณา Logout แล้ว Login ใหม่อีกครั้ง');
}

/*
|--------------------------------------------------------------------------
| ดึงรายการเฉพาะของผู้ Login เท่านั้น
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        r.id,
        r.request_no,
        r.branch_code,
        r.branch_name,
        r.request_reason,
        r.status,
        r.requested_by,
        r.requested_at,
        r.matched_by,
        r.matched_at,
        i.hdd_serial
    FROM harddisk_delivery_requests r
    LEFT JOIN harddisk_request_items i
        ON i.request_id = r.id
        AND i.scan_status = 'matched'
    WHERE r.deleted_at IS NULL
      AND r.status = 'matched'
      AND TRIM(r.matched_by) = :current_user_name
    ORDER BY r.matched_at DESC, r.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':current_user_name' => $currentUserName
]);

$requests = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';

require_login();
require_permission('shipment.manage');

?>

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">รายการรอยืนยันจัดส่ง</h4>
            <div class="text-muted">
                ระบบบันทึกคำขอ จับคู่ Serial HDD และติดตามการจัดส่งให้สาขา
            </div>
            <div class="text-muted small mt-1">
                แสดงเฉพาะรายการที่ผู้ยิงเป็น:
                <strong><?php echo e($currentUserName); ?></strong>
            </div>
        </div>

        <a href="../requests/index.php" class="btn btn-outline-secondary">
            กลับหน้ารายการคำขอ
        </a>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">
            ยืนยันจัดส่ง Harddisk เรียบร้อยแล้ว
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger">
            <?php
            $error = $_GET['error'];

            if ($error === 'not_found') {
                echo 'ไม่พบรายการ หรือรายการนี้ไม่ใช่ของผู้ใช้งานที่ Login อยู่';
            } elseif ($error === 'already_shipped') {
                echo 'รายการนี้ถูกจัดส่งแล้ว';
            } elseif ($error === 'serial_empty') {
                echo 'ไม่พบ Serial HDD ของรายการนี้';
            } elseif ($error === 'invalid') {
                echo 'ข้อมูลไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
            } else {
                echo 'เกิดข้อผิดพลาดในการยืนยันจัดส่ง';
            }
            ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">

        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <div class="fw-semibold">
                รายการรอยืนยันจัดส่ง
            </div>

            <div class="text-muted small">
                ทั้งหมด <?php echo number_format(count($requests)); ?> รายการ
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="60">#</th>
                        <th width="170">เลขที่คำขอ</th>
                        <th>สาขา</th>
                        <th width="160">Serial HDD</th>
                        <th width="180">ผู้ยิง</th>
                        <th width="160">วันที่ยิง</th>
                        <th width="130">Action</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (count($requests) === 0): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                ไม่พบรายการรอยืนยันจัดส่งของคุณ
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($requests as $index => $row): ?>
                        <tr>
                            <td>
                                <?php echo $index + 1; ?>
                            </td>

                            <td>
                                <strong><?php echo e($row['request_no'] ?? '-'); ?></strong>
                            </td>

                            <td>
                                <div class="fw-semibold">
                                    <?php echo e(formatBranchCodeConfirm($row['branch_code'] ?? '')); ?>
                                    -
                                    <?php echo e($row['branch_name'] ?? '-'); ?>
                                </div>
                            </td>

                            <td>
                                <code><?php echo e($row['hdd_serial'] ?? '-'); ?></code>
                            </td>

                            <td>
                                <?php echo e($row['matched_by'] ?? '-'); ?>
                            </td>

                            <td>
                                <span class="text-nowrap">
                                    <?php echo e(formatDateTimeThaiConfirm($row['matched_at'] ?? null)); ?>
                                </span>
                            </td>

                            <td>
                                <form method="post"
                                      action="ship.php"
                                      onsubmit="return confirm('ยืนยันจัดส่ง Harddisk รายการนี้ใช่หรือไม่?');">
                                    <?php echo csrf_field(); ?>

                                    <input type="hidden"
                                           name="request_id"
                                           value="<?php echo e($row['id']); ?>">

                                    <button type="submit" class="btn btn-primary btn-sm w-100">
                                        ยืนยันจัดส่ง
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>

            </table>
        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
