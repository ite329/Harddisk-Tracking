<?php

if (!function_exists('branchLabelEnsurePrintHistoryTable')) {
    function branchLabelEnsurePrintHistoryTable(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `harddisk_db`.`branch_label_print_history` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `main_branch_code` VARCHAR(30) NOT NULL,
            `branch_code` VARCHAR(30) NULL,
            `branch_name` VARCHAR(255) NOT NULL,
            `shipping_address` TEXT NULL,
            `asset_name` VARCHAR(150) NULL,
            `print_orientation` VARCHAR(20) NOT NULL DEFAULT 'portrait',
            `print_source` VARCHAR(30) NOT NULL DEFAULT 'direct_branch',
            `printed_by_employee_code` VARCHAR(50) NULL,
            `printed_by_name` VARCHAR(255) NULL,
            `printed_ip` VARCHAR(45) NULL,
            `user_agent` VARCHAR(500) NULL,
            `printed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_branch_label_printed_at` (`printed_at`),
            KEY `idx_branch_label_branch_code` (`branch_code`),
            KEY `idx_branch_label_main_code` (`main_branch_code`),
            KEY `idx_branch_label_employee_code` (`printed_by_employee_code`),
            KEY `idx_branch_label_asset_name` (`asset_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('branchLabelCurrentPrintUser')) {
    function branchLabelCurrentPrintUser(): array
    {
        $fullName = trim((string)($_SESSION['full_name'] ?? ''));
        $employeeCode = trim((string)($_SESSION['employee_code'] ?? $_SESSION['emp_code'] ?? ''));

        if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
            if ($fullName === '') {
                $fullName = trim((string)($_SESSION['user']['full_name'] ?? ''));
            }
            if ($employeeCode === '') {
                $employeeCode = trim((string)($_SESSION['user']['employee_code'] ?? $_SESSION['user']['emp_code'] ?? ''));
            }
        }

        if ($fullName === '') {
            $fullName = $employeeCode !== '' ? $employeeCode : 'ไม่ทราบชื่อผู้ใช้งาน';
        }

        return [$fullName, $employeeCode];
    }
}

if (!function_exists('branchLabelClientIp')) {
    function branchLabelClientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['HTTP_X_REAL_IP'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            foreach (explode(',', (string)$candidate) as $ip) {
                $ip = trim($ip);
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                    return substr($ip, 0, 45);
                }
            }
        }

        return '';
    }
}
