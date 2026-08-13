<?php 
session_start();

if (!isset($_SESSION['id']) || !isset($_SESSION['pass'])) {
    echo "<script>alert('กรุณา login ก่อน'); window.location='login1.php';</script>";
    exit();
}

$id = (int)$_SESSION['id'];

include "connect_mtc.php";

$km_sn = isset($_POST["km_sn"]) ? trim($_POST["km_sn"]) : "";

$sql6 = "SELECT * FROM `login` WHERE l_id=$id";			
$na = mysqli_query($conn, $sql6);
$rs6 = mysqli_fetch_array($na);

if ($km_sn == "") {
    $sql = "SELECT * FROM `keyboard_mouse_diy` WHERE km_poin2='0'";
} else {
    $km_sn_safe = mysqli_real_escape_string($conn, $km_sn);
    $sql = "SELECT * FROM `keyboard_mouse_diy` WHERE km_sn='$km_sn_safe'";
}
$nquery = mysqli_query($conn, $sql);
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>ข้อมูล Keyboard & Mouse</title>

<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" />

<style>
body {
    background-color: #e8f5e9; /* สีเขียวอ่อน very light mint green */
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 15px;
    color:rgb(44, 202, 242); /* สีเขียวเข้ม แต่ไม่จัดเกินไป */
    padding-top: 20px;
    margin: 0;
}

.container {
    max-width: 1100px;
    margin: auto;
    background: #ffffff;
    border-radius: 8px;
    box-shadow: 0 6px 18px rgba(46, 125, 50, 0.15); /* shadow สีเขียวจางๆ */
    padding: 25px 30px;
}

.sidebar {
    background-color: #a5d6a7; /* green lighten-3 */
    padding: 20px;
    border-radius: 8px;
    min-height: 600px;
    color:rgb(0, 123, 255); /* dark green */
}
.sidebar h4 {
    text-align: center;
    margin-bottom: 30px;
    font-weight: 700;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: #2e7d32;
}
.sidebar .nav-pills > li > a {
    color: #1b5e20;
    font-weight: 600;
    padding: 12px 20px;
    margin-bottom: 10px;
    border-radius: 5px;
    background-color: #c8e6c9; /* green lighten-4 */
    transition: background-color 0.3s ease;
}
.sidebar .nav-pills > li > a:hover,
.sidebar .nav-pills > li.active > a {
    background-color: #66bb6a !important; /* green lighten-1 */
    color: #ffffff !important;
}

/* Header */
.page-header {
    margin-bottom: 35px;
    border-bottom: 2px solid #66bb6a;
}
.page-header h2 {
    color: #388e3c;
    font-weight: 700;
}

/* Buttons */
.btn-warning {
    background-color: #81c784; /* green lighten-2 */
    border-color: #66bb6a;
    color: #1b5e20;
    font-weight: 600;
    margin-right: 10px;
    transition: background-color 0.3s ease;
}
.btn-warning:hover {
    background-color: #4caf50;
    border-color: #388e3c;
    color: #ffffff;
}
.btn-primary {
    background-color: #4caf50;
    border-color: #388e3c;
    color: #fff;
    font-weight: 600;
    transition: background-color 0.3s ease;
}
.btn-primary:hover {
    background-color: #357a38;
    border-color: #2e7d32;
}

/* Table */
.table {
    margin-top: 15px;
}
.table > thead > tr > th {
    background-color: #81c784;
    color: #1b5e20;
    font-weight: 700;
    text-align: center;
}
.table > tbody > tr:nth-child(odd) {
    background-color: #dcedc8; /* green lighten-4 */
}
.table > tbody > tr:nth-child(even) {
    background-color: #c5e1a5; /* green lighten-3 */
}
.table > tbody > tr > td {
    vertical-align: middle !important;
    text-align: center;
    font-weight: 500;
    color:rgb(19, 16, 246);
}

/* Modal */
.modal-header {
    background-color: #66bb6a;
    color: #fff;
    font-weight: 700;
}
.modal-footer .btn-default {
    background-color: #a5d6a7;
    color: #1b5e20;
}

/* Tooltip */
.showTooltip {
    float: left;
    padding: 10px;
    background: #f1f8e9;
    border: 2px solid #aed581;
    border-radius: 6px;
    color: #33691e;
    position: absolute;
    max-width: 300px;
    z-index: 1050;
}
</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/2.2.0/jquery.min.js"></script>  
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/js/bootstrap.min.js"></script> 

<script>
$(document).ready(function(){
    // Tooltip AJAX
    $('.lnk').hover(function(e) {
        $('body').append('<div class="showTooltip"></div>');
        var tooltip = $('.showTooltip');
        $.ajax({
            url: $(this).attr('turl') + '&' + new Date().getTime(),
            beforeSend: function() {
                tooltip.html('<img src="wait.gif"/>');
            },
            success: function(data) {
                tooltip.html(data);
            }
        });
        var mousex = e.pageX + 20;
        var mousey = e.pageY - 200;
        var tooltipWidth = tooltip.width();
        var tooltipHeight = tooltip.height();
        var winWidth = $(window).width();
        var winHeight = $(window).height() + $(window).scrollTop();

        if ((winWidth - (mousex + tooltipWidth)) < 10) {
            mousex = e.pageX - tooltipWidth - 40;
        }
        if ((winHeight - (mousey + tooltipHeight)) < 10) {
            mousey = e.pageY - tooltipHeight - 10;
        }
        tooltip.css({top: mousey, left: mousex, display: 'none'}).slideDown('slow');
    }, function() {
        $('.showTooltip').remove();
    });

    // Datepicker
    $("#dateInput").datepicker({
        dateFormat: 'yy-m-dd'
    });
});

// Number input validation
function check_key_number(event) {
    var key = event.keyCode || event.which;
    if (key !== 13 && (key < 48 || key > 57)) {
        event.preventDefault();
    }
}
</script>


    <link href="/harddisk_delivery_web/assets/css/hdd-sarabun-font.css?v=20260727" rel="stylesheet">
</head>

<body>

<div class="container">
  <div class="row">
<div >
   <?php  include "menu.php"; ?>
    </div>
    <!-- Main Content -->
    <section class="col-md-9">
      <header class="page-header">
        <h2>รายการข้อมูลอุปกรณ์ซ่อม</h2>
      </header>

      <div class="text-right" style="margin-bottom: 25px;">
        <button type="button" onclick="window.location.href='report_km.php'" class="btn btn-warning">รายงาน</button>
        <button type="button" data-toggle="modal" data-target="#add_data_Modal" class="btn btn-warning">เพิ่ม Keyboard & Mouse</button>
        <button type="button" data-toggle="modal" data-target="#add_data_mouse" class="btn btn-warning">เพิ่ม Mouse</button>
      </div>

      <table class="table table-hover table-bordered">
        <thead>
          <tr>
            <th>ลำดับ</th>
            <th>S/N</th>
            <th>รายการทรัพย์สิน</th>
            <th>วันรับเข้า</th>
            <th>ผู้นำเข้า</th>
            <th>สถานะ</th>
            <th>กดเบิก</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          $a=0; 
          $i=1;
          while($rs = mysqli_fetch_array($nquery)){
              $sql3 = "SELECT * FROM `login` WHERE l_id=" . (int)$rs['km_l_id'];
              $result3 = mysqli_query($conn, $sql3);
              $rs3 = mysqli_fetch_array($result3);
              $bgcolor = ($a % 2 == 0) ? '#d4f0e8' : '#b7e6db';
          ?>
          <tr style="background-color: <?php echo $bgcolor; ?>;">
            <td class="text-center"><?php echo $i; ?></td>
            <td class="text-center"><?php echo htmlspecialchars($rs["km_sn"]); ?></td>
            <td class="text-center">
              <?php 
              echo ($rs["km_poin"] == 1) ? "Keyboard & Mouse" : "";
              echo ($rs["km_poin"] == 2) ? "Mouse" : "";
              ?>
            </td>
            <td class="text-center"><?php echo htmlspecialchars($rs["km_day1"]); ?></td>
            <td class="text-center"><?php echo htmlspecialchars($rs3["l_name"]); ?></td>
            <td class="text-center">-</td>
            <td class="text-center">
              <?php if ($rs["km_poin2"] == 0) { ?>
                <button type="button" class="btn btn-primary btn-xs" onclick="location.href='frm_add_km.php'">เบิก</button>
              <?php } else { ?>
                <span class="label label-success">เบิกไปแล้ว</span>
              <?php } ?>
            </td>
          </tr>
          <?php  
              $a++;	
              $i++; 
          }  
          ?>
        </tbody>
      </table>

    </section>

  </div>
</div>

<!-- Modal เพิ่ม Keyboard & Mouse -->
<div id="add_data_Modal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="addKeyModalLabel">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form id="add_key_form" action="save_key.php" method="post" enctype="multipart/form-data" class="form-horizontal">
        <div class="modal-header bg-success text-white">
          <button type="button" class="close" data-dismiss="modal">&times;</button>
          <h4 class="modal-title" id="addKeyModalLabel">เพิ่ม Keyboard & Mouse</h4>
        </div>
        <div class="modal-body">
          <label>พนักงานเพิ่มข้อมูล: <?php echo htmlspecialchars($rs6['l_name']); ?></label>
          <input type="hidden" name="l_id" value="<?php echo $id; ?>">
          <input type="hidden" name="km_poin" value="1">
          <div class="form-group">
            <label for="km_sn_key">S/N</label>
            <input type="text" name="b_id" id="km_sn_key" class="form-control" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success">บันทึก</button>
          <button type="button" class="btn btn-default" data-dismiss="modal">ปิด</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal เพิ่ม Mouse -->
<div id="add_data_mouse" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="addMouseModalLabel">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form id="add_mouse_form" action="save_key.php" method="post" enctype="multipart/form-data" class="form-horizontal">
        <div class="modal-header bg-success text-white">
          <button type="button" class="close" data-dismiss="modal">&times;</button>
          <h4 class="modal-title" id="addMouseModalLabel">เพิ่ม Mouse</h4>
        </div>
        <div class="modal-body">
          <label>พนักงานเพิ่มข้อมูล: <?php echo htmlspecialchars($rs6['l_name']); ?></label>
          <input type="hidden" name="l_id" value="<?php echo $id; ?>">
          <input type="hidden" name="km_poin" value="2">
          <div class="form-group">
            <label for="km_sn_mouse">S/N</label>
            <input type="text" name="b_id" id="km_sn_mouse" class="form-control" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success">บันทึก</button>
          <button type="button" class="btn btn-default" data-dismiss="modal">ปิด</button>
        </div>
      </form>
    </div>
  </div>
</div>

</body>
</html>
