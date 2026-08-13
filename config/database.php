<?php
/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
| Stack: PHP 7.4+, MySQL 5.7, PDO
| Timezone: Asia/Bangkok / +07:00
*/

$appTimezone = 'Asia/Bangkok';
date_default_timezone_set($appTimezone);

$DB_HOST = 'localhost';
$DB_NAME = 'harddisk_db';
$DB_USER = 'root';
$DB_PASS = '';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    // Keep MySQL session timezone aligned with PHP pages that use NOW(), CURDATE(), etc.
    try {
        $pdo->exec("SET time_zone = '+07:00'");
    } catch (Throwable $e) {
        // Some hosts may restrict SET time_zone. The application can still use PHP date().
    }
} catch (PDOException $e) {
    die('Database connection failed. Please check config/database.php');
}
