<?php
/**
 * Database connection for Repair System.
 * This system still uses mysqli and expects $conn.
 */
date_default_timezone_set('Asia/Bangkok');

$servername = 'localhost';
$username = 'root';
$password = '';
$dbname = 'data';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed: ' . mysqli_connect_error());
}

mysqli_set_charset($conn, 'utf8');
?>
