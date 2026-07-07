<?php

/*
|--------------------------------------------------------------------------
| Escape Output
|--------------------------------------------------------------------------
*/
if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/*
|--------------------------------------------------------------------------
| Request Status Badge
|--------------------------------------------------------------------------
*/
if (!function_exists('status_request_badge')) {
    function status_request_badge($status)
    {
        switch ($status) {
            case 'pending_scan':
                return '<span class="badge bg-warning text-dark">รอยิงบาร์โค้ด</span>';

            case 'matched':
                return '<span class="badge bg-info text-dark">รอยืนยันจัดส่ง</span>';

            case 'shipped':
                return '<span class="badge bg-primary">จัดส่งแล้ว</span>';

            case 'received':
                return '<span class="badge bg-success">สาขาได้รับแล้ว</span>';

            case 'cancelled':
                return '<span class="badge bg-danger">ยกเลิก</span>';

            default:
                return '<span class="badge bg-secondary">ไม่ทราบสถานะ</span>';
        }
    }
}

if (!function_exists('status_request_text')) {
    function status_request_text($status)
    {
        switch ($status) {
            case 'pending_scan':
                return 'รอยิงบาร์โค้ด';

            case 'matched':
                return 'รอยืนยันจัดส่ง';

            case 'shipped':
                return 'จัดส่งแล้ว';

            case 'received':
                return 'สาขาได้รับแล้ว';

            case 'cancelled':
                return 'ยกเลิก';

            default:
                return 'ไม่ทราบสถานะ';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Shipment Status Badge
|--------------------------------------------------------------------------
*/
if (!function_exists('status_shipment_badge')) {
    function status_shipment_badge($status)
    {
        switch ($status) {
            case 'pending':
                return '<span class="badge bg-warning text-dark">รอดำเนินการ</span>';

            case 'shipped':
                return '<span class="badge bg-primary">จัดส่งแล้ว</span>';

            case 'received':
                return '<span class="badge bg-success">สาขาได้รับแล้ว</span>';

            case 'cancelled':
                return '<span class="badge bg-danger">ยกเลิก</span>';

            default:
                return '<span class="badge bg-secondary">ไม่ทราบสถานะ</span>';
        }
    }
}

if (!function_exists('status_shipment_text')) {
    function status_shipment_text($status)
    {
        switch ($status) {
            case 'pending':
                return 'รอดำเนินการ';

            case 'shipped':
                return 'จัดส่งแล้ว';

            case 'received':
                return 'สาขาได้รับแล้ว';

            case 'cancelled':
                return 'ยกเลิก';

            default:
                return 'ไม่ทราบสถานะ';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Inventory Status Badge
|--------------------------------------------------------------------------
*/
if (!function_exists('status_inventory_badge')) {
    function status_inventory_badge($status)
    {
        switch ($status) {
            case 'available':
                return '<span class="badge bg-success">พร้อมใช้งาน</span>';

            case 'reserved':
                return '<span class="badge bg-warning text-dark">ถูกจอง</span>';

            case 'shipped':
                return '<span class="badge bg-primary">จัดส่งแล้ว</span>';

            case 'used':
                return '<span class="badge bg-info text-dark">ใช้งานแล้ว</span>';

            case 'damaged':
                return '<span class="badge bg-danger">ชำรุด</span>';

            case 'cancelled':
                return '<span class="badge bg-secondary">ยกเลิก</span>';

            default:
                return '<span class="badge bg-secondary">ไม่ทราบสถานะ</span>';
        }
    }
}

if (!function_exists('status_inventory_text')) {
    function status_inventory_text($status)
    {
        switch ($status) {
            case 'available':
                return 'พร้อมใช้งาน';

            case 'reserved':
                return 'ถูกจอง';

            case 'shipped':
                return 'จัดส่งแล้ว';

            case 'used':
                return 'ใช้งานแล้ว';

            case 'damaged':
                return 'ชำรุด';

            case 'cancelled':
                return 'ยกเลิก';

            default:
                return 'ไม่ทราบสถานะ';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Date Format
|--------------------------------------------------------------------------
*/
if (!function_exists('format_datetime_thai')) {
    function format_datetime_thai($value)
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
}

if (!function_exists('format_date_thai')) {
    function format_date_thai($value)
    {
        if (empty($value)) {
            return '-';
        }

        $timestamp = strtotime($value);

        if (!$timestamp) {
            return '-';
        }

        return date('d/m/Y', $timestamp);
    }
}

/*
|--------------------------------------------------------------------------
| Branch Code Format
|--------------------------------------------------------------------------
*/
if (!function_exists('format_branch_code')) {
    function format_branch_code($branchCode)
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
}

/*
|--------------------------------------------------------------------------
| Current User Helper
|--------------------------------------------------------------------------
*/
if (!function_exists('get_current_user_full_name')) {
    function get_current_user_full_name($pdo = null)
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

        if ($pdo && $userId > 0) {
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

        if ($pdo && $employeeCode !== '') {
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

        if (!empty($_SESSION['employee_code'])) {
            return trim($_SESSION['employee_code']);
        }

        if (!empty($_SESSION['user']['employee_code'])) {
            return trim($_SESSION['user']['employee_code']);
        }

        return '';
    }
}

/*
|--------------------------------------------------------------------------
| Current User Role
|--------------------------------------------------------------------------
*/
if (!function_exists('get_current_user_role')) {
    function get_current_user_role()
    {
        if (!empty($_SESSION['role'])) {
            return trim($_SESSION['role']);
        }

        if (!empty($_SESSION['user']['role'])) {
            return trim($_SESSION['user']['role']);
        }

        return '';
    }
}

/*
|--------------------------------------------------------------------------
| Check Admin
|--------------------------------------------------------------------------
*/
if (!function_exists('is_admin_user')) {
    function is_admin_user()
    {
        return get_current_user_role() === 'admin';
    }
}

/*
|--------------------------------------------------------------------------
| Safe Number
|--------------------------------------------------------------------------
*/
if (!function_exists('number_or_zero')) {
    function number_or_zero($value)
    {
        return number_format((int)$value);
    }
}

/*
|--------------------------------------------------------------------------
| Check Delete Permission
|--------------------------------------------------------------------------
| อนุญาตให้ลบเฉพาะ Admin รหัสพนักงาน 14329
|--------------------------------------------------------------------------
*/
if (!function_exists('can_delete_records')) {
    function can_delete_records($pdo = null)
    {
        $employeeCode = '';

        if (!empty($_SESSION['employee_code'])) {
            $employeeCode = trim($_SESSION['employee_code']);
        } elseif (!empty($_SESSION['user']['employee_code'])) {
            $employeeCode = trim($_SESSION['user']['employee_code']);
        }

        if ($pdo && !empty($_SESSION['user_id'])) {
            $sql = "
                SELECT employee_code, role
                FROM users
                WHERE id = :id
                  AND deleted_at IS NULL
                  AND is_active = 1
                LIMIT 1
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':id' => (int)$_SESSION['user_id']
            ]);

            $user = $stmt->fetch();

            if ($user) {
                return $user['employee_code'] === '14329' && $user['role'] === 'admin';
            }
        }

        return false;
    }
}
