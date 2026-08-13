<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('current_employee_code')) {
    function current_employee_code(): string
    {
        foreach (['employee_code','employee_id','emp_code','emp_id','username','user_id'] as $key) {
            if (!empty($_SESSION[$key])) return trim((string)$_SESSION[$key]);
        }
        if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
            foreach (['employee_code','employee_id','emp_code','emp_id','username','id'] as $key) {
                if (!empty($_SESSION['user'][$key])) return trim((string)$_SESSION['user'][$key]);
            }
        }
        return '';
    }
}

if (!function_exists('central_permission_user_key')) {
    function central_permission_user_key(): string
    {
        $id = $_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? '');
        $id = trim((string)$id);
        return $id !== '' ? $id : current_employee_code();
    }
}

if (!function_exists('super_admin_employee_codes')) {
    function super_admin_employee_codes(): array
    {
        return ['14329','10057','16470','00106'];
    }
}

if (!function_exists('is_super_admin_employee')) {
    function is_super_admin_employee(): bool
    {
        return in_array(current_employee_code(), super_admin_employee_codes(), true);
    }
}

if (!function_exists('current_user_role')) {
    function current_user_role(): string
    {
        if (is_super_admin_employee()) return 'super_admin';
        foreach (['role','user_role'] as $key) {
            if (!empty($_SESSION[$key])) return strtolower(trim((string)$_SESSION[$key]));
        }
        if (!empty($_SESSION['user']['role'])) return strtolower(trim((string)$_SESSION['user']['role']));
        return '';
    }
}

if (!function_exists('permission_tables_ready')) {
    function permission_tables_ready(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('roles','permissions','role_permissions','user_roles','user_permissions')");
            return (int)$stmt->fetchColumn() === 5;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('load_user_permissions')) {
    function load_user_permissions(PDO $pdo, ?string $userKey = null): array
    {
        if (is_super_admin_employee()) return ['*'];
        $userKey = trim((string)($userKey ?? central_permission_user_key()));
        if ($userKey === '' || !permission_tables_ready($pdo)) return [];

        $permissions = [];
        try {
            $sql = "SELECT DISTINCT p.permission_code
                    FROM user_roles ur
                    INNER JOIN roles r ON r.id = ur.role_id AND r.is_active = 1
                    INNER JOIN role_permissions rp ON rp.role_id = r.id
                    INNER JOIN permissions p ON p.id = rp.permission_id AND p.is_active = 1
                    WHERE ur.user_key = :user_key";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':user_key' => $userKey]);
            $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

            $stmt = $pdo->prepare("SELECT p.permission_code, up.permission_type
                                   FROM user_permissions up
                                   INNER JOIN permissions p ON p.id = up.permission_id AND p.is_active = 1
                                   WHERE up.user_key = :user_key");
            $stmt->execute([':user_key' => $userKey]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $code = (string)$row['permission_code'];
                if ($row['permission_type'] === 'deny') {
                    $permissions = array_values(array_diff($permissions, [$code]));
                } else {
                    $permissions[] = $code;
                }
            }
        } catch (Throwable $e) {
            return [];
        }
        return array_values(array_unique(array_map('strval', $permissions)));
    }
}

if (!function_exists('central_permission_has_assignment')) {
    function central_permission_has_assignment(PDO $pdo, ?string $userKey = null): bool
    {
        $userKey = trim((string)($userKey ?? central_permission_user_key()));
        if ($userKey === '' || !permission_tables_ready($pdo)) return false;
        try {
            $stmt = $pdo->prepare("SELECT (EXISTS(SELECT 1 FROM user_roles WHERE user_key=:u1) OR EXISTS(SELECT 1 FROM user_permissions WHERE user_key=:u2))");
            $stmt->execute([':u1' => $userKey, ':u2' => $userKey]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('boot_user_permissions')) {
    function boot_user_permissions(PDO $pdo, bool $force = false): array
    {
        $loadedAt = (int)($_SESSION['central_permissions_loaded_at'] ?? 0);
        if (!$force && isset($_SESSION['central_permissions']) && is_array($_SESSION['central_permissions']) && $loadedAt > 0 && (time() - $loadedAt) < 60) {
            return $_SESSION['central_permissions'];
        }
        $_SESSION['central_permissions'] = load_user_permissions($pdo);
        $_SESSION['central_permissions_loaded_at'] = time();
        return $_SESSION['central_permissions'];
    }
}

if (!function_exists('legacy_permission_allowed')) {
    function legacy_permission_allowed(string $permission): bool
    {
        if (is_super_admin_employee()) return true;
        $role = current_user_role();
        if (in_array($role, ['admin','administrator','super_admin','super_user','superuser'], true)) return true;

        $map = [
            'user.manage' => ['can_manage_users','is_admin','is_super_admin'],
            'permission.manage' => ['can_manage_users','is_admin','is_super_admin'],
            'request.view' => ['can_manage_requests'], 'request.create' => ['can_manage_requests'],
            'request.edit' => ['can_manage_requests'], 'request.delete' => ['can_manage_requests'], 'request.status.manage' => ['can_manage_requests'],
            'inventory.view' => ['can_manage_inventory'], 'inventory.create' => ['can_manage_inventory'],
            'inventory.edit' => ['can_manage_inventory'], 'inventory.delete' => ['can_manage_inventory'],
            'shipment.view' => ['can_manage_shipments'], 'shipment.manage' => ['can_manage_shipments'],
            'asset.view' => ['can_manage_assets'], 'asset.manage' => ['can_manage_assets'],
            'server.view' => ['can_manage_assets'], 'server.manage' => ['can_manage_assets'],
            'it_system.view' => ['can_manage_assets'], 'it_system.manage' => ['can_manage_assets'],
            'license_software.view' => ['can_manage_assets'], 'license_software.manage' => ['can_manage_assets'],
            'notebook.view' => ['can_manage_assets'], 'notebook.manage' => ['can_manage_assets'],
            'branch_label.view' => ['can_manage_assets'], 'branch_label.manage' => ['can_manage_assets'],
            'delete_computer.view' => ['can_manage_assets'], 'delete_computer.manage' => ['can_manage_assets'],
            'keyboard_mouse.view' => ['can_manage_assets'], 'keyboard_mouse.manage' => ['can_manage_assets'],
            'wcs_quote.view' => ['can_manage_assets'], 'wcs_quote.manage' => ['can_manage_assets'],
            'computer_external.view' => ['can_manage_assets'],
            'admin.branch_import' => ['can_manage_users','is_admin','is_super_admin'],
            'admin.asset_import' => ['can_manage_assets','is_admin','is_super_admin'],
            'admin.online_users' => ['can_manage_users','is_admin','is_super_admin'],
            'report.view' => ['can_view_reports'],
        ];
        foreach ($map[$permission] ?? [] as $key) {
            $value = $_SESSION[$key] ?? ($_SESSION['user'][$key] ?? null);
            if (in_array(strtolower(trim((string)$value)), ['1','true','yes','on'], true)) return true;
        }
        return false;
    }
}

if (!function_exists('can')) {
    function can(string $permission, bool $legacyFallback = true): bool
    {
        $permission = trim($permission);
        if ($permission === '') return false;
        if (is_super_admin_employee()) return true;

        global $pdo;
        if ((!isset($_SESSION['central_permissions']) || !is_array($_SESSION['central_permissions'])) && isset($pdo) && $pdo instanceof PDO) {
            boot_user_permissions($pdo);
        }
        $permissions = $_SESSION['central_permissions'] ?? [];
        if (in_array('*', $permissions, true) || in_array($permission, $permissions, true)) return true;

        $parts = explode('.', $permission);
        while (count($parts) > 1) {
            array_pop($parts);
            if (in_array(implode('.', $parts) . '.*', $permissions, true)) return true;
        }
        if (!$legacyFallback) return false;

        // เมื่อ User ถูกกำหนด Role/Permission ในระบบกลางแล้ว ให้ระบบกลางเป็นแหล่งสิทธิ์หลัก
        // ไม่ย้อนกลับไปใช้ Flag แบบเดิม เพื่อให้สามารถถอนสิทธิ์จากหน้าจัดการส่วนกลางได้จริง
        if (isset($pdo) && $pdo instanceof PDO && central_permission_has_assignment($pdo)) {
            return false;
        }

        // รองรับระบบเดิมเฉพาะ User ที่ยังไม่ได้ถูกย้ายเข้าระบบสิทธิ์ส่วนกลาง
        return legacy_permission_allowed($permission);
    }
}

if (!function_exists('require_permission')) {
    function require_permission(string $permission): void
    {
        if (can($permission)) return;
        http_response_code(403);
        exit('คุณไม่มีสิทธิ์ดำเนินการในส่วนนี้');
    }
}

if (!function_exists('require_any_permission')) {
    function require_any_permission(array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (can((string)$permission)) return;
        }
        http_response_code(403);
        exit('คุณไม่มีสิทธิ์ดำเนินการในส่วนนี้');
    }
}

if (!function_exists('can_edit_hdd_request')) {
    function can_edit_hdd_request(): bool { return can('request.edit'); }
}
if (!function_exists('can_delete_hdd_request')) {
    function can_delete_hdd_request(): bool { return can('request.delete'); }
}
