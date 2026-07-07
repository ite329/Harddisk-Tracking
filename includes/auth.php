<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function current_user_name() {
    return $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Guest';
}

function current_user_role() {
    return $_SESSION['role'] ?? 'guest';
}

function require_login() {
    if (empty($_SESSION['user_id'])) {
        header('Location: /harddisk_delivery_web/public/login.php');
        exit;
    }
}

function require_role(array $roles) {
    require_login();
    if (!in_array(current_user_role(), $roles, true)) {
        http_response_code(403);
        die('Permission denied');
    }
}
