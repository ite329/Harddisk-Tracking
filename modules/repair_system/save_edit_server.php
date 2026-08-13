<?php
include "connect_mtc.php";

// ป้องกัน SQL Injection
$s_id = isset($_POST["s_id"]) ? intval($_POST["s_id"]) : 0;
$s_nameserver = mysqli_real_escape_string($conn, $_POST["s_nameserver"]);
$s_sn = mysqli_real_escape_string($conn, $_POST["s_sn"]);
$s_system = mysqli_real_escape_string($conn, $_POST["s_system"]);
$s_caretaker = mysqli_real_escape_string($conn, $_POST["s_caretaker"]);
$s_location = mysqli_real_escape_string($conn, $_POST["s_location"]);
$s_day = $_POST["s_day"];

if ($s_id == 0) {
    // INSERT
    $sql = "INSERT INTO `server` (`s_nameserver`, `s_dns_name`, `s_sn`, `s_processor`, `s_memory_size`, `s_memory_type`, `s_disk_size`, `s_disk_type`, `s_day`, `s_caretaker`, `s_location`) 
            VALUES (`$s_nameserver`, `$s_dns_name`, `$s_sn`, `$s_processor`, `$s_memory_size`, `$s_memory_type`, `$s_disk_size`, `$s_disk_type`, `$s_day`, `$s_caretaker`, `$s_location`)";
} else {
    // UPDATE
    $sql = "UPDATE `server` SET `s_day`='$s_day',`s_caretaker`='$s_caretaker',`s_location`='$s_location' WHERE s_id=$s_id";
}

if (mysqli_query($conn, $sql)) {
    mysqli_close($conn);
    echo "<script>alert('บันทึกข้อมูลเรียบร้อยแล้ว'); window.location.href='server.php';</script>";
    exit();
} else {
    echo "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . mysqli_error($conn);
    mysqli_close($conn);
}
?>
