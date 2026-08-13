<?php
$redirect = $_GET['redirect'] ?? '';
$url = 'public/login.php';
if ($redirect !== '') {
    $url .= '?redirect=' . rawurlencode($redirect);
}
header('Location: ' . $url);
exit;
