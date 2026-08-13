<?php
/**
 * Shared authentication guard for merged internal systems.
 * Use this file at the top of pages that require login.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function current_employee_code(): string
{
    $candidates = [
        $_SESSION['employee_code'] ?? null,
        $_SESSION['employee_id'] ?? null,
        $_SESSION['emp_id'] ?? null,
        $_SESSION['id'] ?? null,
        $_SESSION['username'] ?? null,
        $_SESSION['user']['employee_code'] ?? null,
        $_SESSION['user']['employee_id'] ?? null,
    ];

    foreach ($candidates as $value) {
        $value = trim((string)($value ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function current_user_full_name(): string
{
    $candidates = [
        $_SESSION['full_name'] ?? null,
        $_SESSION['name'] ?? null,
        $_SESSION['login_name'] ?? null,
        $_SESSION['user']['full_name'] ?? null,
    ];

    foreach ($candidates as $value) {
        $value = trim((string)($value ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return current_employee_code();
}

function require_login(): void
{
    if (current_employee_code() !== '') {
        return;
    }

    $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
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
    $loginUrl = rtrim($baseUrl, '/') . '/public/login.php';

    header('Location: ' . $loginUrl . '?redirect=' . rawurlencode($currentUrl));
    exit;
}
