<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_login();

$pageTitle = 'ยิงบาร์โค้ด HDD';

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function getCurrentUserFullNameForScanPage($pdo)
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
            SELECT first_name, last_name
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
            $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

            if ($fullName !== '') {
                return $fullName;
            }
        }
    }

    if ($employeeCode !== '') {
        $sql = "
            SELECT first_name, last_name
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
            $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

            if ($fullName !== '') {
                return $fullName;
            }
        }
    }

    if (!empty($_SESSION['first_name']) || !empty($_SESSION['last_name'])) {
        return trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    }

    if (!empty($_SESSION['user']['first_name']) || !empty($_SESSION['user']['last_name'])) {
        return trim(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? ''));
    }

    if (!empty($_SESSION['full_name'])) {
        return trim($_SESSION['full_name']);
    }

    if (!empty($_SESSION['user']['full_name'])) {
        return trim($_SESSION['user']['full_name']);
    }

    return '';
}

function formatDateTimeForScanPage($value)
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

function formatBranchCodeForScanPage($branchCode)
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

/*
|--------------------------------------------------------------------------
| Current Login User
|--------------------------------------------------------------------------
| ใช้สำหรับแสดงชื่อคนที่ Login และส่งไปให้ match.php บันทึก matched_by
|--------------------------------------------------------------------------
*/

$currentUserName = getCurrentUserFullNameForScanPage($pdo);

if ($currentUserName === '') {
    die('ไม่พบชื่อผู้ Login กรุณา Logout แล้ว Login ใหม่อีกครั้ง');
}

/*
|--------------------------------------------------------------------------
| Request ID
|--------------------------------------------------------------------------
*/

$requestId = 0;

if (isset($_GET['request_id'])) {
    $requestId = (int)$_GET['request_id'];
} elseif (isset($_GET['id'])) {
    $requestId = (int)$_GET['id'];
}

/*
|--------------------------------------------------------------------------
| Fetch selected request
|--------------------------------------------------------------------------
| ดึงรายการที่เลือก โดยไม่กรองว่าใครเป็นผู้บันทึก
|--------------------------------------------------------------------------
*/

$request = null;

if ($requestId > 0) {
    $requestSql = "
        SELECT
            id,
            request_no,
            main_branch_code,
            branch_code,
            branch_name,
            request_reason,
            status,
            requested_by,
            requested_at
        FROM harddisk_delivery_requests
        WHERE id = :id
          AND deleted_at IS NULL
          AND status = 'pending_scan'
        LIMIT 1
    ";

    $requestStmt = $pdo->prepare($requestSql);
    $requestStmt->execute([
        ':id' => $requestId
    ]);

    $request = $requestStmt->fetch();
}

/*
|--------------------------------------------------------------------------
| Fetch pending scan list
|--------------------------------------------------------------------------
| แสดงรายการรอยิงบาร์โค้ดทั้งหมดของ IT ทุกคน
|--------------------------------------------------------------------------
*/

$listSql = "
    SELECT
        id,
        request_no,
        main_branch_code,
        branch_code,
        branch_name,
        request_reason,
        status,
        requested_by,
        requested_at
    FROM harddisk_delivery_requests
    WHERE deleted_at IS NULL
      AND status = 'pending_scan'
    ORDER BY requested_at DESC, id DESC
";

$listStmt = $pdo->prepare($listSql);
$listStmt->execute();

$pendingRequests = $listStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">ยิงบาร์โค้ด HDD</h4>
            <div class="text-muted">
                แสดงรายการรอยิงบาร์โค้ดทั้งหมดของ IT ทุกคน
            </div>
            <div class="text-muted small mt-1">
                ผู้ที่ Login และจะถูกบันทึกเป็นผู้ยิงบาร์โค้ด:
                <strong><?php echo e($currentUserName); ?></strong>
            </div>
        </div>

        <a href="../requests/index.php" class="btn btn-outline-secondary">
            กลับหน้ารายการคำขอ
        </a>
    </div>

    <?php if ($requestId > 0 && !$request): ?>
        <div class="alert alert-warning">
            ไม่พบคำขอ หรือสถานะไม่ใช่ “รอยิงบาร์โค้ด”
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">
            บันทึก Serial HDD และจับคู่คำขอเรียบร้อยแล้ว
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger">
            <?php
            $error = $_GET['error'];

            if ($error === 'duplicate_serial') {
                echo 'Serial HDD นี้ถูกใช้งานแล้ว';
            } elseif ($error === 'empty_serial') {
                echo 'กรุณายิงบาร์โค้ด Serial HDD';
            } elseif ($error === 'not_found') {
                echo 'ไม่พบคำขอที่ต้องการจับคู่';
            } elseif ($error === 'already_matched') {
                echo 'คำขอนี้มี Serial HDD ที่จับคู่แล้ว';
            } elseif ($error === 'hdd_not_found') {
                echo 'ไม่พบ Serial HDD นี้ในคลัง';
            } elseif ($error === 'hdd_not_available') {
                echo 'Serial HDD นี้ไม่อยู่ในสถานะพร้อมใช้งาน';
            } else {
                echo 'เกิดข้อผิดพลาดในการบันทึกข้อมูล';
            }
            ?>
        </div>
    <?php endif; ?>

    <?php if ($request): ?>

        <div class="row g-3">

            <div class="col-lg-7">

                <div class="card shadow-sm border-0 mb-3">
                    <div class="card-header bg-white">
                        ข้อมูลคำขอ
                    </div>

                    <div class="card-body">

                        <div class="row mb-3">
                            <div class="col-md-4 text-muted">
                                เลขที่คำขอ
                            </div>
                            <div class="col-md-8 fw-semibold">
                                <?php echo e($request['request_no'] ?? '-'); ?>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-4 text-muted">
                                รหัสสาขา
                            </div>
                            <div class="col-md-8 fw-semibold text-primary">
                                <?php echo e(formatBranchCodeForScanPage($request['main_branch_code'] ?? '')); ?>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-4 text-muted">
                                ชื่อสาขา
                            </div>
                            <div class="col-md-8 fw-semibold">
                                <?php echo e($request['branch_name'] ?? '-'); ?>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-4 text-muted">
                                สาเหตุ
                            </div>
                            <div class="col-md-8">
                                <?php echo nl2br(e($request['request_reason'] ?? '-')); ?>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-4 text-muted">
                                ผู้บันทึก
                            </div>
                            <div class="col-md-8">
                                <?php echo e($request['requested_by'] ?? '-'); ?>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 text-muted">
                                วันที่บันทึก
                            </div>
                            <div class="col-md-8">
                                <?php echo e(formatDateTimeForScanPage($request['requested_at'] ?? null)); ?>
                            </div>
                        </div>

                    </div>
                </div>

            </div>

            <div class="col-lg-5">

                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white">
                        ยิงบาร์โค้ด Serial HDD
                    </div>

                    <div class="card-body">

                        <form method="post" action="match.php" id="scanForm">
                            <?php echo csrf_field(); ?>

                            <input type="hidden"
                                   name="request_id"
                                   value="<?php echo e($request['id']); ?>">

                            <div class="mb-3">
                                <label class="form-label">
                                    Serial HDD
                                </label>

                                <input type="text"
                                       name="hdd_serial"
                                       id="hdd_serial"
                                       class="form-control form-control-lg text-center"
                                       placeholder="ยิงบาร์โค้ด Serial HDD ที่นี่"
                                       autocomplete="off"
                                       autofocus
                                       required>
                            </div>

                            <div class="alert alert-info mb-3">
                                เมื่อยิงบาร์โค้ดแล้ว ระบบจะบันทึกให้อัตโนมัติ
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                บันทึก Serial HDD
                            </button>

                        </form>

                    </div>
                </div>

            </div>

        </div>

    <?php else: ?>

        <div class="card shadow-sm border-0">

            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div class="fw-semibold">
                    รายการรอยิงบาร์โค้ด
                </div>

                <div class="text-muted small">
                    ทั้งหมด <?php echo number_format(count($pendingRequests)); ?> รายการ
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="60">#</th>
                            <th width="160">เลขที่คำขอ</th>
                            <th width="110">รหัสสาขา</th>
                            <th>ชื่อสาขา</th>
                            <th>สาเหตุ</th>
                            <th width="180">ผู้บันทึก</th>
                            <th width="170">วันที่บันทึก</th>
                            <th width="150">ดำเนินการ</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (count($pendingRequests) === 0): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    ไม่พบรายการรอยิงบาร์โค้ด
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($pendingRequests as $index => $row): ?>
                            <tr>
                                <td>
                                    <?php echo $index + 1; ?>
                                </td>

                                <td>
                                    <strong><?php echo e($row['request_no'] ?? '-'); ?></strong>
                                </td>

                                <td>
                                    <span class="fw-semibold text-primary">
                                        <?php echo e(formatBranchCodeForScanPage($row['main_branch_code'] ?? '')); ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="fw-semibold">
                                        <?php echo e($row['branch_name'] ?? '-'); ?>
                                    </div>
                                </td>

                                <td>
                                    <?php echo nl2br(e($row['request_reason'] ?? '-')); ?>
                                </td>

                                <td>
                                    <?php echo e($row['requested_by'] ?? '-'); ?>
                                </td>

                                <td>
                                    <span class="text-nowrap">
                                        <?php echo e(formatDateTimeForScanPage($row['requested_at'] ?? null)); ?>
                                    </span>
                                </td>

                                <td>

                                 <a href="print_label.php?request_id=<?php echo (int)$row['id']; ?>"
                                            target="_blank"
                                            class="btn btn-sm btn-outline-danger w-100">
                                            🖨️ ปริ้นที่อยู่
                                    </a>

                                    <a href="index.php?request_id=<?php echo e($row['id']); ?>"
                                       class="btn btn-primary btn-sm w-100">
                                        ยิงบาร์โค้ด
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>

                </table>
            </div>

        </div>

    <?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const hddInput = document.getElementById('hdd_serial');
    const scanForm = document.getElementById('scanForm');

    if (!hddInput || !scanForm) {
        return;
    }

    hddInput.focus();

    hddInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();

            const serial = hddInput.value.trim();

            if (serial === '') {
                return;
            }

            scanForm.submit();
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
