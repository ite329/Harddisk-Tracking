<?php
session_start();
if (!isset($_SESSION['id']) || !isset($_SESSION['pass'])) {
    echo "<script> alert('กรุณาเข้าสู่ระบบก่อน'); window.location='login1.php';</script>";
    exit();
}

include "connect_mtc.php";
$id = $_SESSION['id'];
$ls_status2 = isset($_POST["ls_status2"]) ? $_POST["ls_status2"] : 1; // ค่าเริ่มต้นเป็น 1

$sql_chart = "SELECT ls_list, COUNT(*) AS total FROM license_software WHERE ls_status2=$ls_status2 GROUP BY ls_list";
$result_chart = mysqli_query($conn, $sql_chart);
?>

<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <title>ข้อมูล License Software</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" />
  <link href="https://fonts.googleapis.com/css2?family=Kanit&display=swap" rel="stylesheet" />
  <style>
    body {
      font-family: 'Kanit', sans-serif;
      background-color: #f4f6f7;
      padding-top: 30px;
    }
    h3, h4 {
      color: #2c3e50;
      margin-bottom: 20px;
    }
    .table th, .table td {
      text-align: center;
      vertical-align: middle;
    }
    .table thead {
      background-color: #27ae60;
      color: white;
    }
    .btn {
      margin-left: 5px;
    }
    .panel-box {
      background-color: #ffffff;
      padding: 20px;
      border-radius: 8px;
      box-shadow: 0 0 5px rgba(0,0,0,0.1);
    }
    .sidebar {
      background-color: #e9f7ef;
      padding: 15px;
      border-radius: 8px;
      min-height: 400px;
    }
    .nav-menu li {
      list-style: none;
      margin-bottom: 10px;
    }
    .nav-menu li a {
      display: block;
      padding: 10px;
      background-color: #d5f5e3;
      color: #2c3e50;
      text-decoration: none;
      border-radius: 5px;
      font-weight: bold;
    }
    .nav-menu li a:hover {
      background-color: #28a745;
      color: white;
    }
  </style>

    <link href="/harddisk_delivery_web/assets/css/hdd-sarabun-font.css?v=20260727" rel="stylesheet">
</head>
<body>

<div class="container">
  <h3 class="text-center">รายการ License Software</h3>
  
  <form method="post" action="show_software.php" class="form-inline text-center">
  <div class="form-group">
    <label for="ls_status2">เลือกข้อมูล:</label>
    <select name="ls_status2" id="ls_status2" class="form-control" onchange="this.form.submit()">
      <option value="0" <?php if($ls_status2 == "0") echo "selected"; ?>>ไม่ได้ใช้งาน</option>
      <option value="1" <?php if($ls_status2 == "1") echo "selected"; ?>>ไม่มีหมดอายุ</option>
      <option value="2" <?php if($ls_status2 == "2") echo "selected"; ?>>รอต่ออายุ</option>
      <option value="3" <?php if($ls_status2 == "3") echo "selected"; ?>>ไม่มีข้อมูล</option>
      <option value="4" <?php if($ls_status2 == "4") echo "selected"; ?>>ค่าติดตั้ง</option>
	    <option value="4" <?php if($ls_status2 == "6") echo "selected"; ?>>กำลังใช้งานอยู่</option> 
    </select>
  </div>
</form>

  <hr />

  <div class="row">
    <!-- เมนูแนวตั้ง -->
    <?php  include "menu.php"; ?>
    <!-- เนื้อหาหลัก -->
    <div class="col-md-9 panel-box">  
      <h4 class="text-center">จำนวน License Software</h4>
      <table class="table table-bordered table-striped">
        <thead>
          <tr> 
            <th>ชื่อโปรแกรม</th>
            <th>จำนวนที่มี</th>
          </tr>
        </thead>
        <tbody>
        <?php while ($row = mysqli_fetch_assoc($result_chart)) { ?>
          <tr> 
            <td><?php echo htmlspecialchars($row['ls_list']); ?></td>
            <td><?php echo $row['total']; ?></td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
    </div>

  </div>
</div>

</body>
</html>
