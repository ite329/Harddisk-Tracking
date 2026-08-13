<?php
session_start();
if (!isset($_SESSION['id']) || !isset($_SESSION['pass'])) {
    echo "<script> alert('กรุณาเข้าสู่ระบบก่อน'); window.location='login1.php';</script>";
    exit();
}

include "connect_mtc.php";
$id = $_SESSION['id'];
$d_sn = $_POST['d_sn'] ?? '';

// ดึงข้อมูลผู้ใช้งาน
$sql6 = "SELECT * FROM `login` WHERE l_id=$id";
$na = mysqli_query($conn, $sql6);
$rs6 = mysqli_fetch_array($na);

// ดึงข้อมูลเครื่องที่ยังค้างอยู่
$sql = "SELECT * FROM `delete_computer` WHERE de_poin='2'";
$nquery = mysqli_query($conn, $sql);

// นับจำนวนเครื่องค้าง
$num = mysqli_num_rows($nquery);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>ข้อมูล เครื่อง Join Domain</title>

<!-- Bootstrap 5 CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- jQuery UI CSS -->
<link rel="stylesheet" href="jquery-ui.css" />
<link rel="stylesheet" href="jquery-ui-timepicker-addon.css" />

<!-- Custom CSS -->
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
.table thead th {
    background-color: #27ae60;
    color: #fff;
    text-align: center;
    font-weight: bold;
}
.table tbody tr:nth-child(even) {
    background-color: #e9f7ef;
}
.table tbody tr:nth-child(odd) {
    background-color: #d5f5e3;
}
#menu-container {
    background-color: #fff;
    padding: 15px;
    border: 1px solid #ddd;
    height: 100%;
    box-shadow: 0 0 5px rgba(0,0,0,0.1);
}
/* เมนูซ้ายสีเขียวอ่อน */
.sidebar {
    background-color: #e9f7ef;
    padding: 15px;
    border-radius: 8px;
    box-shadow: inset 0 0 5px rgba(0,0,0,0.1);
    min-height: 500px;
}
.nav-pills .nav-link {
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 5px;
    background-color: #e9f7ef;
    border: 1px solid #b2d8b2;
    border-radius: 6px;
}
.nav-pills .nav-link:hover,
.nav-pills .nav-link.active {
    background-color: #28a745 !important;
    color: white !important;
    border-color: #1e7e34 !important;
}

/* Tooltip */
.showTooltip {
    float: left;
    padding: 10px;
    background: #F3F3F3;
    border: 2px solid #CFCFCF;
    border-radius: 4px;
    color: #333;
    position: absolute;
    z-index: 1051; /* เหนือ modal */
}
</style>


    <link href="/harddisk_delivery_web/assets/css/hdd-sarabun-font.css?v=20260727" rel="stylesheet">
</head>
<body>
<form id="form1" method="post" action="show_del_computer.php">
<div class="container">
  <div class="row">
    <aside class="col-md-3 sidebar">
      <h4>เมนูหลัก</h4>
      <ul class="nav nav-pills flex-column">
        <li class="nav-item"><a class="nav-link" href="index.php">1. หน้าเช็คทรัพย์สิน</a></li>
        <li class="nav-item"><a class="nav-link" href="server.php">2. เครื่อง server</a></li>
        <li class="nav-item"><a class="nav-link" href="system_information.php">3. ข้อมูลระบบไอที</a></li>
        <li class="nav-item"><a class="nav-link" href="show_software.php">4.Software License</a></li>
        <li class="nav-item"><a class="nav-link" href="report2.php">5. ปริ้นที่อยุ่ส่งสาขา</a></li>
        <li class="nav-item"><a class="nav-link" href="show_NB.php">6. ข้อมูล License NB</a></li>
        <li class="nav-item"><a class="nav-link" href="show_com_re.php">7. Keyboard & Mouse</a></li>
        <li class="nav-item"><a class="nav-link" href="show_drum.php">8. เบิกDrum</a></li>
        <li class="nav-item"><a class="nav-link" href="show_diy_repair.php">9. ส่งอุปกรณ์ HDD</a></li>
        <li class="nav-item"><a class="nav-link active" aria-current="page" href="show_del_computer.php">10. ลบเครื่อง Joindomain</a></li>
        <li class="nav-item"><a class="nav-link" href="../serial_computer/show_sncom.php" target="_blank" rel="noopener">11. ข้อมูลคอมพิวเตอร์</a></li>
        <li class="nav-item"><a class="nav-link" href="logout.php">ออกจากระบบ</a></li>
      </ul>
    </aside>

    <main class="col-md-9">
      <div class="mb-3 d-flex justify-content-between align-items-center">
        <h3 class="text-center flex-grow-1">รายการข้อมูลอุปกรณ์คอมพิวเตอร์</h3>
        <div>
          <button type="button" onclick="window.location.href='report_del_comname.php'" class="btn btn-warning me-2">รายงานที่ลบไปแล้ว</button>
          <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#addDataModal">ลงชื่อเครื่องคอม</button>
        </div>
      </div>

      <div class="text-center mb-3">
        <span style="font-size: 26px; color: #FF0000;">
          จำนวนเครื่องที่ค้างเหลืออยู่ <?= $num; ?> เครื่อง
        </span>
      </div>

      <div class="table-responsive">
      <table class="table table-bordered text-center align-middle">
        <thead>
          <tr>
            <th style="width:40px;">ลำดับ</th>
            <th style="width:90px;">ชื่อเครื่องใหม่</th>
            <th style="width:90px;">ชื่อเครื่องเก่า</th>
            <th style="width:70px;">ชื่อผู้แจ้งลบ</th>
            <th style="width:140px;">หมายเหตุ</th>
            <th style="width:50px;">ลบเครื่อง</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          $a = 0; 
          $i = 1;
          mysqli_data_seek($nquery, 0); // rewind result pointer
          while($rs = mysqli_fetch_array($nquery)){
            $sql3 = "SELECT * FROM `login` WHERE l_id = {$rs['de_name_l_new']}";
            $result3 = mysqli_query($conn, $sql3);
            $rs3 = mysqli_fetch_array($result3);

            $rowColor = ($a % 2 == 0) ? "#3f9" : "#CCFFFF";
          ?>
          <tr style="background-color: <?= $rowColor ?>">
            <td><?= $i ?></td>
            <td><?= htmlspecialchars($rs['name_com_new']) ?></td>
            <td><?= htmlspecialchars($rs['name_com_del']) ?></td>
            <td><?= htmlspecialchars($rs3['l_name']) ?></td>
            <td><?= htmlspecialchars($rs['de_commer']) ?></td>
            <td>
              <a href="del_computer_name.php?de_co_id=<?= $rs['de_co_id'] ?>&id=<?= $id ?>" class="text-decoration-none">
                <img src="images/delete.ico" width="25" height="25" alt="ลบเครื่อง" />
              </a>
            </td>
          </tr>

          <!-- Modal ลบเครื่อง -->
          <div class="modal fade" id="deleteModal<?= $i ?>" tabindex="-1" aria-labelledby="deleteModalLabel<?= $i ?>" aria-hidden="true">
            <div class="modal-dialog">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title" id="deleteModalLabel<?= $i ?>">ลบเครื่อง <?= htmlspecialchars($rs['name_com_del']) ?></h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="del_computer_name.php" class="p-3">
                  <input type="hidden" name="de_co_id" value="<?= $rs['de_co_id'] ?>">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <p>ยืนยันการลบชื่อเครื่อง <?= htmlspecialchars($rs['name_com_del']) ?> หรือไม่?</p>
                  <div class="text-center my-3">
                    <img src="images/NO_SN.png" width="50" height="100" alt="ยืนยันลบเครื่อง" />
                  </div>
                  <div class="text-center">
                    <button type="submit" class="btn btn-success">ยืนยัน</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                  </div>
                </form>
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
      </div>
    </main>
  </div>
</div>
</form>

<!-- Modal เพิ่มชื่อเครื่อง -->
<div class="modal fade" id="addDataModal" tabindex="-1" aria-labelledby="addDataModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="insert_form" action="save_del_comname.php" method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
        <div class="modal-header">
          <h5 class="modal-title" id="addDataModalLabel">กรอกชื่อเครื่องคอม</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <label>คนแจ้ง: <?= htmlspecialchars($rs6['l_name']) ?></label>
          <input type="hidden" name="l_id" value="<?= $id ?>">
          <div class="mb-3">
            <label for="name_com_new" class="form-label">ชื่อเครื่องคอมใหม่</label>
            <input name="name_com_new" type="text" class="form-control" id="name_com_new" required />
            <div class="invalid-feedback">กรุณากรอกชื่อเครื่องคอมใหม่</div>
          </div>
          <div class="mb-3">
            <label for="name_com_del" class="form-label">ชื่อเครื่องคอมเก่า</label>
            <input name="name_com_del" type="text" class="form-control" id="name_com_del" required />
            <div class="invalid-feedback">กรุณากรอกชื่อเครื่องคอมเก่า</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success">บันทึก</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- jQuery -->
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<!-- Bootstrap 5 Bundle JS (Popper + Bootstrap) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery UI JS -->
<script src="jquery-ui.min.js"></script>
<script src="jquery-ui-timepicker-addon.js"></script>
<script src="jquery-ui-sliderAccess.js"></script>
<script src="../jquery.js"></script>

<script>
// Tooltip Ajax Functionality
$(document).ready(function() {
    $TtoolTipAjax();
});
function $TtoolTipAjax() {
    $('.lnk').hover(function(e) {
        $('body').append('<div class="showTooltip"></div>');
        var showTooltip = $('.showTooltip');
        $.ajax({
            url: $(this).attr('turl') + '&' + new Date().getTime(),
            beforeSend: function() {
                showTooltip.html('<img src="wait.gif"/>');
            },
            success: function(data) {
                showTooltip.html(data);
            }
        });
        var mousex = e.pageX + 20;
        var mousey = e.pageY - 200;
        var tooltipWidth = showTooltip.width();
        var tooltipHeight = showTooltip.height();
        var toolVisX = $(window).width() - (mousex + tooltipWidth);
        var toolVisY = ($(window).height() + $(window).scrollTop()) - (mousey + tooltipHeight);
        if (toolVisX < 10) {
            mousex = e.pageX - tooltipWidth - 40;
        }
        if (toolVisY < 10) {
            mousey = e.pageY - tooltipHeight - 10;
        }
        showTooltip.css({
            top: mousey,
            left: mousex,
            display: 'none'
        });
        showTooltip.slideDown('slow');
    }, function() {
        $('.showTooltip').remove();
    });
}

// Datepicker Init
$(function() {
    $("#dateInput").datepicker({
        dateFormat: 'yy-m-dd'
    });
});

// Bootstrap 5 form validation
(function () {
  'use strict'
  var forms = document.querySelectorAll('.needs-validation')
  Array.prototype.slice.call(forms).forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!form.checkValidity()) {
        event.preventDefault()
        event.stopPropagation()
      }
      form.classList.add('was-validated')
    }, false)
  })
})();

</script>

</body>
</html>
