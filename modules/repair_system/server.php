<?php
session_start();
$id = $_SESSION['id'];
$pass = $_SESSION['pass'];
$s_nameserver=$_POST["s_nameserver"];

if (isset($_SESSION["id"]) && isset($_SESSION["pass"])) {
    include "connect_mtc.php";

    if($s_nameserver!=""){
      $stmt = $conn->prepare("SELECT * FROM `server` WHERE s_nameserver LIKE ? OR s_dns_name LIKE ?");
      $search = "%$s_nameserver%";
      $stmt->bind_param("ss", $search, $search); // สองตัวแปร
      $stmt->execute();
      $result1 = $stmt->get_result();
    }else{
    // กำหนดจำนวนข้อมูลต่อหน้า
    $per_page = 12;

    // รับค่าหน้าปัจจุบันจาก $_GET
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($page < 1) $page = 1;

    // คำนวณ offset
    $offset = ($page - 1) * $per_page;

    // นับจำนวนแถวทั้งหมดในตาราง server
    $sql_count = "SELECT COUNT(*) as total FROM `server`";
    $result_count = mysqli_query($conn, $sql_count);
    $row_count = mysqli_fetch_assoc($result_count);
    $total_rows = $row_count['total'];

    // คำนวณจำนวนหน้าทั้งหมด
    $total_pages = ceil($total_rows / $per_page);

    // ดึงข้อมูลเฉพาะหน้าปัจจุบัน โดยเรียงจากวันที่ใกล้ปัจจุบันที่สุด
    $sql1 = "SELECT * FROM `server` 
             ORDER BY ABS(DATEDIFF(s_day, CURDATE())) ASC 
             LIMIT $offset, $per_page";
    $result1 = mysqli_query($conn, $sql1);
    }
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" 
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>โปรแกรมเช็ครายการ Server</title>

<!-- Bootstrap CSS -->
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" />

<!-- jQuery & Bootstrap JS -->
<script src="https://ajax.googleapis.com/ajax/libs/jquery/2.2.0/jquery.min.js"></script>  
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/js/bootstrap.min.js"></script> 

<!-- jQuery UI -->
<link rel="stylesheet" href="jquery-ui.css" />
<link rel="stylesheet" href="jquery-ui-timepicker-addon.css" />
<script src="jquery-ui.min.js"></script>
<script src="jquery-ui-timepicker-addon.js"></script>
<script src="jquery-ui-sliderAccess.js"></script>
<script src="../jquery.js"></script>

<style>
body {
    background-color: #f7f7f7;
    font-family: 'Tahoma', Geneva, sans-serif;
    font-size: 14px;
    color: #333;
    padding-top: 20px;
}
h2.title {
    text-align: center;
    margin-bottom: 30px;
    font-weight: bold;
    color: #2c3e50;
}
.table > thead > tr > th {
    background-color: #27ae60;
    color: #fff;
    text-align: center;
    font-weight: bold;
    
}
.table > tbody > tr:nth-child(even) {
    background-color: #e9f7ef;
}
.table > tbody > tr:nth-child(odd) {
    background-color: #d5f5e3;
}
.pagination {
    margin: 20px 0;
    text-align: center;
}
.pagination > li > a, .pagination > li > span {
    color: #27ae60;
}
.pagination > li.active > a, .pagination > li.active > span {
    background-color: #27ae60;
    border-color: #27ae60;
    color: #fff;
}
#menu-container {
    background-color: #fff;
    padding: 15px;
    border: 1px solid #ddd;
    height: 100%;
    box-shadow: 0 0 5px rgba(0,0,0,0.1);
}

 /* ตกแต่งกล่องเมนูซ้าย */
        .col-md-3 {
            background-color: #e9f7ef; /* สีเขียวอ่อน */
            padding: 15px;
            border-radius: 8px;
            box-shadow: inset 0 0 5px rgba(0,0,0,0.1);
            min-height: 500px;
        }

        /* เมนูเป็นรายการแนวตั้ง */
        .nav-pills > li > a {
            font-weight: 600;
            color: #2c3e50;
            padding: 10px 15px;
            border-radius: 6px;
            margin-bottom: 5px;
            background-color: #e9f7ef;
            border: 1px solid #b2d8b2;
        }
        .nav-pills > li > a:hover,
        .nav-pills > li.active > a {
            background-color: #28a745 !important;
            color: white !important;
            border-color: #1e7e34 !important;
        }
</style>

<script>
$(function() {
    $(".datepicker").datepicker({
        dateFormat: "yy-mm-dd"
    });
});
</script>


    <link href="/harddisk_delivery_web/assets/css/hdd-sarabun-font.css?v=20260727" rel="stylesheet">
</head>
<body>
<div class="container">
  <h3 class="text-center">รายการ Server</h3>

  <?php
      $current_date = date("Y-m-d");
  ?>
  <div class="text-center" style="margin-bottom: 20px;">
      <h4>วันที่ปัจจุบัน: <span class="label label-success"><?php echo $current_date; ?></span></h4>
  </div>
  
  <form method="post" action="server.php" class="form-inline text-center">
    <div class="form-group">
      <label for="">ซื่อเครื่อง:</label>
      <input type="text" name="s_nameserver" id="s_nameserver" class="form-control" />
    </div>
    <button type="submit" class="btn btn-primary">ค้นหา</button>
    <button type="button" class="btn btn-success" data-toggle="modal" data-target="#add_data_Modal">
      <i class="glyphicon glyphicon-plus"></i> เพิ่มข้อมูล
    </button>
  </form>

  <hr />
    <div class="row">
        <div >
   <?php  include "menu.php"; ?>
    </div>

        <!-- ตารางข้อมูล -->
        <div class="col-md-9">
            <table class="table table-bordered table-striped table-hover" >
                <thead>
                    <tr>
                        <th style="width: 70px;">จำนวน</th>
                        <th>เครื่อง</th>
                        <th style="width: 160px;">S/N</th>
                        <th style="width: 140px;">DNS</th>
                        <th style="width: 140px;">วันหมดอายุ</th>
                        <th style="width: 120px;">ดูแล</th>
                        <th style="width: 120px;">ที่อยู่</th>
                        <th style="width: 120px;">ข้อมูลเครื่อง</th>
                    </tr>
                </thead>
                <tbody>
                <?php 
                $a = 0;
                $i =1;
                while ($f = mysqli_fetch_array($result1)) {
                ?>
                    <tr>
                        <td class="text-center"><?php echo htmlspecialchars($i); ?></td>
                        <td><?php echo htmlspecialchars($f["s_nameserver"]); ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($f["s_sn"]); ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($f["s_dns_name"]); ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($f["s_day"]); ?>
                        <a href="#" data-toggle="modal" data-target="#edit_day<?php echo $i; ?>"><img src="images/edit.ico" width="10" height="10" alt="แก้ไข" />
                        </a></td>
                        <td class="text-center"><?php echo htmlspecialchars($f["s_caretaker"]); ?>&nbsp;&nbsp;
                        <a href="#" data-toggle="modal" data-target="#editCaretakerModal<?php echo $i; ?>"><img src="images/edit.ico" width="10" height="10" alt="แก้ไข" />
                        </a></td>
                        <td class="text-center"><?php echo htmlspecialchars($f["s_location"]); ?>&nbsp;&nbsp;
                        <a href="#" data-toggle="modal" data-target="#editlocation<?php echo $i; ?>"><img src="images/edit.ico" width="10" height="10" alt="แก้ไข" />
                        </a></td>
                        <td class="text-center"><button class="btn btn-sm btn-warning" data-toggle="modal" data-target="#list<?php echo $i; ?>">รายการ</button>
</td>
                        

                    </tr>

<!-- Modal แก้ไขผู้ดูแล -->
<div id="editCaretakerModal<?php echo $i; ?>" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="caretakerModalLabel<?php echo $i; ?>">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header bg-info">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title">แก้ไขผู้ดูแล</h4>
      </div>
      <div class="modal-body">
        <form action="save_edit_server.php" method="post">
          <input type="hidden" name="s_id" value="<?php echo $f['s_id']; ?>">
          <input type="hidden" name="s_day" value="<?php echo $f['s_day']; ?>">
          <input type="hidden" name="s_location" value="<?php echo $f['s_location']; ?>">
          <div class="form-group">
            <label>ชื่อผู้ดูแลใหม่</label>
            <input type="text" name="s_caretaker" class="form-control" value="<?php echo htmlspecialchars($f['s_caretaker']); ?>" required>
          </div>
          <button type="submit" class="btn btn-success">บันทึก</button>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">ปิด</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal ที่อยู่อุปกรณ์ -->
<div id="editlocation<?php echo $i; ?>" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="caretakerModalLabel<?php echo $i; ?>">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header bg-info">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title">แก้ไขสถานที่ตั้ง</h4>
      </div>
      <div class="modal-body">
        <form action="save_edit_server.php" method="post">
          <input type="hidden" name="s_id" value="<?php echo $f['s_id']; ?>">
          <input type="hidden" name="s_day" value="<?php echo $f['s_day']; ?>">
          <input type="hidden" name="s_caretaker" value="<?php echo $f['s_caretaker']; ?>">
          <div class="form-group">
            <label>สถานที่ตั้ง</label>
            <input type="text" name="s_location" class="form-control" value="<?php echo htmlspecialchars($f['s_location']); ?>" required>
          </div>
          <button type="submit" class="btn btn-success">บันทึก</button>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">ปิด</button>
      </div>
    </div>
  </div>
</div>

 <!-- Modal แก้ไข วันหมดอายุ -->
                    <div id="edit_day<?php echo $i; ?>" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="day<?php echo $i; ?>">
                      <div class="modal-dialog" role="document">
                        <div class="modal-content">
                          <div class="modal-header bg-primary">
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                            <h4 class="modal-title">แก้ไขวันที่หมดอายุ</h4>
                          </div>
                          <div class="modal-body">
                            <form class="ajax-form" action="save_edit_server.php" method="post" novalidate>
                              <div class="form-group">
                                <label>  คนแก้ไข: <?php echo htmlspecialchars($rs6['l_name']); ?></label>
                                <input type="hidden" name="s_id" value="<?php echo $f['s_id']; ?>">
                                <input type="hidden" name="s_caretaker" value="<?php echo $f['s_caretaker']; ?>">
                                <input type="hidden" name="s_location" value="<?php echo $f['s_location']; ?>">
                                <input name="s_day" type="text" id="dateInput<?php echo $i; ?>" class="form-control datepicker" value="0000-00-00">

                              </div>
                              <button type="submit" class="btn btn-success btn-block">บันทึก</button>
                            </form>
                          </div>
                          <div class="modal-footer">
                            <button type="button" class="btn btn-default" data-dismiss="modal">ปิด</button>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Modal รายละเอียดของตัวเครื่อง-->
                    <div id="list<?php echo $i; ?>" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="list<?php echo $i; ?>">
                      <div class="modal-dialog" role="document">
                        <div class="modal-content">
                          <div class="modal-header bg-primary">
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                            <h4 class="modal-title">ข้อมูลประจำตัวเครื่อง Server</h4>
                          </div>
                          <div class="modal-body">
                            <form class="ajax-form" action="" method="post" novalidate>
                              
                              <label>  Processor: <?php echo $f["s_processor"]; ?></label><br>
                                <label>  Memory_size: <?php echo $f["s_memory_size"]; ?></label><br>
                                <label>  Memory_type: <?php echo $f["s_memory_type"]; ?></label><br>
                                <label>  Disk_size: <?php echo $f["s_disk_size"]; ?></label><br>
                                <label>  Disk_type: <?php echo $f["s_disk_type"]; ?></label><br>
                                </div>
                              
                            </form>
                          </div>
                        </div>
                      </div>
                    </div>

                <?php
                    $a++;
                    $i++;
                } 
                ?>
                </tbody>
            </table>

            <!-- แสดงลิงก์แบ่งหน้า -->
            <nav aria-label="Page navigation">
                <ul class="pagination">
                <?php
                // ลิงก์ไปหน้าก่อนหน้า (disabled ถ้าเป็นหน้าแรก)
                $prev_page = $page - 1;
                if ($prev_page < 1) {
                    echo '<li class="disabled"><span>&laquo;</span></li>';
                } else {
                    echo '<li><a href="?page='.$prev_page.'" aria-label="Previous">&laquo;</a></li>';
                }

                // แสดงเลขหน้า
                for ($i = 1; $i <= $total_pages; $i++) {
                    if ($i == $page) {
                        echo '<li class="active"><span>'.$i.' <span class="sr-only">(current)</span></span></li>';
                    } else {
                        echo '<li><a href="?page='.$i.'">'.$i.'</a></li>';
                    }
                }

                // ลิงก์ไปหน้าถัดไป (disabled ถ้าเป็นหน้าสุดท้าย)
                $next_page = $page + 1;
                if ($next_page > $total_pages) {
                    echo '<li class="disabled"><span>&raquo;</span></li>';
                } else {
                    echo '<li><a href="?page='.$next_page.'" aria-label="Next">&raquo;</a></li>';
                }
                ?>
                </ul>
            </nav>
        </div>
    </div>

</div>



<!-- Modal เพิ่มข้อมูล -->
<div id="add_data_Modal" class="modal fade" role="dialog">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title">เพิ่มข้อมูล Server</h4>
      </div>
      <div class="modal-body">
        <form id="insert_form" action="save_edit_server.php" method="post">
          <div class="form-group">
            <label>ซื่อเครื่อง</label>
            <input type="text" name="s_nameserver" class="form-control" />
          </div>
          <div class="form-group">
            <label>Dns_name</label>
            <input type="text" name="s_dns_name" class="form-control" />
          </div>
          <div class="form-group">
            <label>S/N :M/T </label>
            <input type="text" name="s_sn" class="form-control" />
          </div>
          <div class="form-group">
            <label>Processor</label>
            <input type="text" name="s_processor" class="form-control" />
          </div>
          <div class="form-group">
            <label>Memory_size</label>
            <input type="text" name="s_memory_size" class="form-control" />
          </div>
          <div class="form-group">
            <label>Memory_type</label>
            <input type="text" name="s_memory_type" class="form-control" />
          </div>
          <div class="form-group">
            <label>Disk_size</label>
            <input type="text" name="s_disk_size" class="form-control" />
          </div>
          <div class="form-group">
            <label>Disk_type</label>
            <input type="text" name="s_disk_type" class="form-control" />
          </div>
          <div class="form-group">
            <label>ผู้ดูแล</label>
            <input type="text" name="s_caretaker" class="form-control" />
          </div>
          <div class="form-group">
            <label>ที่ตั้ง</label>
            <input type="text" name="s_location" class="form-control" />
          </div>
          <div class="form-group">
            <label>วันที่หมดอายุ</label>
            <input type="text" name="s_day" class="form-control datepicker" value="0000-00-00" />
          </div>
          <input type="submit" name="insert" value="บันทึกข้อมูล" class="btn btn-success" />
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">ปิด</button>
      </div>
    </div>
  </div>
</div>
</body>
</html>

<?php
    mysqli_close($conn);
} else {
    echo "<script>alert('กรุณา login ก่อน'); window.location='login1.php';</script>";
    exit();
}
?>

