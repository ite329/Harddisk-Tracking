<?php
/*
|--------------------------------------------------------------------------
| Data Database Connection
|--------------------------------------------------------------------------
| ใช้สำหรับเชื่อมฐานข้อมูล Data ตาราง asset
| ค่าเริ่มต้นจะใช้ Host/User/Password เดียวกับ config/database.php
| ถ้าฐานข้อมูล Data ใช้ Credential คนละชุด ให้แก้ค่าด้านล่างนี้ได้
*/

$appTimezone = 'Asia/Bangkok';
date_default_timezone_set($appTimezone);

$DATA_DB_HOST = $DATA_DB_HOST ?? ($DB_HOST ?? 'localhost');
$DATA_DB_NAME = $DATA_DB_NAME ?? 'Data';
$DATA_DB_USER = $DATA_DB_USER ?? ($DB_USER ?? 'root');
$DATA_DB_PASS = $DATA_DB_PASS ?? ($DB_PASS ?? '');

$dataPdo = null;
$dataDbError = '';

try {
    $dataPdo = new PDO(
        "mysql:host={$DATA_DB_HOST};dbname={$DATA_DB_NAME};charset=utf8mb4",
        $DATA_DB_USER,
        $DATA_DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    try {
        $dataPdo->exec("SET time_zone = '+07:00'");
    } catch (Throwable $e) {
        // บาง Server อาจไม่อนุญาตให้ SET time_zone แต่ระบบยังใช้งานได้
    }
} catch (Throwable $e) {
    $dataDbError = 'ไม่สามารถเชื่อมต่อฐานข้อมูล Data ได้ กรุณาตรวจสอบ config/data_database.php';
    $dataPdo = null;
}
