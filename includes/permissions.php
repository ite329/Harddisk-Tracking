<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('current_employee_code')) {
    function current_employee_code(): string
    {
        if (!empty($_SESSION['employee_code'])) {
            return trim((string)$_SESSION['employee_code']);
        }

        if (!empty($_SESSION['user']['employee_code'])) {
            return trim((string)$_SESSION['user']['employee_code']);
        }

        if (!empty($_SESSION['user_employee_code'])) {
            return trim((string)$_SESSION['user_employee_code']);
        }

        return '';
    }
}

if (!function_exists('current_user_role')) {
    function current_user_role(): string
    {
        if (!empty($_SESSION['role'])) {
            return strtolower(trim((string)$_SESSION['role']));
        }

        if (!empty($_SESSION['user']['role'])) {
            return strtolower(trim((string)$_SESSION['user']['role']));
        }

        if (!empty($_SESSION['user_role'])) {
            return strtolower(trim((string)$_SESSION['user_role']));
        }

        return '';
    }
}

if (!function_exists('can_delete_hdd_request')) {
    function can_delete_hdd_request(): bool
    {
        $employeeCode = current_employee_code();
        $role = current_user_role();

        /*
         * ผู้มีสิทธิ์ลบรายการคำขอส่ง HDD
         */
        $allowedEmployeeCodes = [
            '14329',
        ];

        if (in_array($employeeCode, $allowedEmployeeCodes, true)) {
            return true;
        }

        /*
         * คงสิทธิ์ Admin เดิมไว้
         */
        if (in_array($role, ['admin', 'administrator', 'super_admin'], true)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('can_edit_hdd_request')) {
    function can_edit_hdd_request(): bool
    {
        $employeeCode = current_employee_code();
        $role = current_user_role();

        /*
         * ผู้มีสิทธิ์แก้ไขรายการคำขอส่ง HDD
         */
        $allowedEmployeeCodes = [
            '14329',
        ];

        if (in_array($employeeCode, $allowedEmployeeCodes, true)) {
            return true;
        }

        /*
         * คงสิทธิ์ Admin เดิมไว้
         */
        if (in_array($role, ['admin', 'administrator', 'super_admin'], true)) {
            return true;
        }

        return false;
    }
}

