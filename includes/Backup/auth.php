<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/permissions.php';

if (!function_exists('current_user_name')) {
    function current_user_name(): string
    {
        return (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Guest');
    }
}

if (!function_exists('require_login')) {
    function require_login(): void
    {
        if (empty($_SESSION['user_id']) && empty($_SESSION['employee_code']) && empty($_SESSION['id']) && empty($_SESSION['user']['id'])) {
            $current = $_SERVER['REQUEST_URI'] ?? '';
            $scriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
            $baseUrl = '';
            foreach (['/modules/', '/public/'] as $marker) {
                $pos = strpos($scriptPath, $marker);
                if ($pos !== false) {
                    $baseUrl = substr($scriptPath, 0, $pos);
                    break;
                }
            }
            if ($baseUrl === '') {
                $baseUrl = '/harddisk_delivery_web';
            }
            if ($current === '') {
                $current = rtrim($baseUrl, '/') . '/modules/requests/create.php';
            }
            $redirect = rtrim($baseUrl, '/') . '/public/login.php?redirect=' . rawurlencode($current);
            header('Location: ' . $redirect);
            exit;
        }
    }
}

if (!function_exists('require_role')) {
    function require_role(array $roles): void
    {
        require_login();
        $currentRole = current_user_role();
        if (!in_array($currentRole, $roles, true) && !can('permission.manage')) {
            http_response_code(403);
            exit('คุณไม่มีสิทธิ์ดำเนินการในส่วนนี้');
        }
    }
}
