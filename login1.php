<?php
$redirect = $_GET['redirect'] ?? '';
$url = 'http://mtcasset.local/harddisk_delivery_web/public/login.php';
if ($redirect !== '') {
    $url .= '?redirect=' . rawurlencode($redirect);
}
header('Location: ' . $url);
exit;
