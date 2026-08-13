<?php 
session_start();

if (!isset($_SESSION['id']) || !isset($_SESSION['pass'])) {
    echo "<script>alert('กรุณา login ก่อน'); window.location='login1.php';</script>";
    exit();
}

$id = (int)$_SESSION['id'];
$pass = $_SESSION['pass'];

include "connect_mtc.php";

$d_sn = isset($_POST["d_sn"]) ? trim($_POST["d_sn"]) : "";

$sql6 = "SELECT * FROM `login` WHERE l_id=$id";			
$na = mysqli_query($conn, $sql6);
$rs6 = mysqli_fetch_assoc($na);

if ($d_sn === "") {
    $sql = "SELECT * FROM `report_diy` WHERE re_diy_poin='0'";
} else {
    $d_sn_safe = mysqli_real_escape_string($conn, $d_sn);
    $sql = "SELECT * FROM `report_diy` WHERE d_sn='$d_sn_safe'";
}
$nquery = mysqli_query($conn, $sql);
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>ข้อมูล DRUM</title>

<!-- Bootstrap CSS -->
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" />

<style>
body {
    background-color: #f0f4f8;
    font-family: 'Tahoma', Geneva, sans-serif;
    font-size: 15px;
    color: #2c3e50;
    padding-top: 20px;
    margin: 0;
}

.container-fluid {
    max-width: 1200px;
    margin: auto;
}

.sidebar {
    background-color: #e0f2f1;
    padding: 20px;
    border-radius: 8px;
    min-height: 100vh;
}

.sidebar h4 {
    color: #00796b;
    font-weight: 700;
    margin-bottom: 25px;
    text-align: center;
}

.sidebar .nav-pills > li > a {
    font-weight: 600;
    color: #004d40;
    padding: 12px 20px;
    margin-bottom: 8px;
    border-radius: 6px;
    background-color: #b2dfdb;
    border: 1px solid #80cbc4;
    transition: background-color 0.3s ease, color 0.3s ease;
}

.sidebar .nav-pills > li > a:hover,
.sidebar .nav-pills > li.active > a {
    background-color: #00796b !important;
    color: white !important;
    border-color: #004d40 !important;
}

.main-content {
    padding: 30px 20px;
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 0 15px rgba(0,0,0,0.1);
    min-height: 100vh;
}

.header-title {
    color: #00796b;
    font-weight: 700;
    margin-bottom: 30px;
    text-align: center;
}

.text-right.mb-3 {
    margin-bottom: 30px;
}

.table > thead > tr > th {
    background-color: #00796b;
    color: #fff;
    font-weight: 700;
    text-align: center;
    vertical-align: middle;
}

.table > tbody > tr:nth-child(even) {
    background-color: #e0f2f1;
}

.table > tbody > tr > td {
    vertical-align: middle;
}

.btn-warning {
    background-color: #ff9800;
    border-color: #fb8c00;
}

.btn-warning:hover {
    background-color: #fb8c00;
    border-color: #f57c00;
}

.btn-primary {
    background-color: #00796b;
    border-color: #00695c;
}

.btn-primary:hover {
    background-color: #00695c;
    border-color: #004d40;
}

/* Modal header */
.modal-header.bg-primary {
    background-color: #00796b;
    color: #fff;
    font-weight: 700;
}

/* Modal header for danger */
.modal-header.bg-danger {
    background-color: #d32f2f;
    color: #fff;
    font-weight: 700;
}

@media (max-width: 767px) {
    .sidebar {
        min-height: auto;
        margin-bottom: 20px;
        border-radius: 8px;
    }
    .main-content {
        min-height: auto;
        padding: 15px 10px;
    }
    .table-responsive {
        border: 0;
    }
}
</style>

<!-- jQuery & Bootstrap JS -->
<script src="https://ajax.googleapis.com/ajax/libs/jquery/2.2.0/jquery.min.js"></script>  
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/js/bootstrap.min.js"></script>  
<script type="text/javascript">
$(document).ready(function(){
     $TtoolTipAjax();
});
$TtoolTipAjax=function(){//ทีทูลทิป ฟังก์ชั่น
$('.lnk').hover(function(e){ //Mouse Hover แอทริบิวต์ คลาส ชื่อ lnk
$('body').append('<div class="showTooltip"> </div>');
var showTooltip=$('.showTooltip');
    $.ajax({//เรียกใช้ ajax ของ jQuery
        url:$(this).attr('turl')+'&'+new Date().getTime(),
        beforeSend :function(){//ก่อนส่งค่า 
             showTooltip.html('<img src="wait.gif"/>'); //แสดงตัว loading 
          },
        success:function(data){//ส่งค่าเสร็จสมบูรณ์ พร้อมกับผลลัพธุ์ถูกส่งกลับมา(data)
            showTooltip.html(data);
       }
    });
var mousex = e.pageX+20 ; 
var mousey = e.pageY-200;  
var tooltipWidth = showTooltip.width(); 
var tooltipHeight = showTooltip.height(); 
var toolVisX = $(window).width() - (mousex + tooltipWidth); 
var toolVisY = ($(window).height()+$(window).scrollTop())-(mousey+tooltipHeight); 
if ( toolVisX < 10 ) {  mousex = e.pageX - tooltipWidth - 40;  }
if ( toolVisY < 10 ) {   mousey = e.pageY - tooltipHeight - 10;  }
showTooltip.css({ top: mousey, left: mousex,display:'none'});
showTooltip.slideDown('slow');
},function(){ //Mouse Out
       $('.showTooltip').remove();//Remove Tooltip
})
}
</script>

<script>
$(function(){//การเลือกสาขา
	$("#b_id").change(function(){
		var pid=$(this).val();
		//alert(pid);
		$.get("data.php",{bod:pid},function(data){
			//alert(data);
			$("#bry_id").children().remove().end();
			$("#bry_id").children().end().append(data);
			$("#bry_id").removeAttr('disabled');
			});		
		});	
	});		
</script>
<script>
$(function(){//รุ่นอุปกร
	$("#r_cho").change(function(){
		var cho=$(this).val();
		//alert(pid);
		$.get("data.php",{cho1:cho},function(data){
			//alert(data);
			$("#r_cho_ru").children().remove().end();
			$("#r_cho_ru").children().end().append(data);
			$("#r_cho_ru").removeAttr('disabled');
			});		
		});	
	});		
</script>
<script type="text/javascript">
$(function(){//วันที่ส่ง
	$("#dateInput").datepicker({
		dateFormat: 'yy-m-dd'
	});
});

</script>



    <link href="/harddisk_delivery_web/assets/css/hdd-sarabun-font.css?v=20260727" rel="stylesheet">
</head>

<body>
<div class="container-fluid">
    <div class="row">
       <div >
   <?php  include "menu.php"; ?>
    </div>
        <main class="col-md-9 main-content">
            <h2 class="header-title">รายการข้อมูล เบิกDrum เครื่องปริ้น</h2>

            <div class="text-right mb-3">
                
                
            </div>

            <?php 
            $r = "SELECT * FROM `diy`";
            $q = mysqli_query($conn, $r);
            $num = mysqli_num_rows($q);

            $r2 = "SELECT * FROM `diy_adapter` WHERE diy_ad_id=1";
            $q2 = mysqli_query($conn, $r2);
            $num2 = mysqli_fetch_assoc($q2);
            ?>

            <div class="row text-center">
                <div class="col-md-6 mb-3">
                    <div class="alert alert-info">
                        <button type="button" class="btn btn-primary mt-2" data-toggle="modal" data-target="#add_data_Modal">เบิก Drum ส่งให้สาขา</button>
                        <button class="btn btn-warning" onclick="window.location.href='report_diy_adapter.php'">รายงาน เบิกDrum ส่งให้สาขา</button>
                    </div>
                </div>
                <div class="col-md-6 mb-3">
                    <div class="alert alert-info">
                        <button type="button" class="btn btn-primary mt-2" data-toggle="modal" data-target="#add_data_Adapter">อาการเสียเครื่องปริ้น</button>
                        <button class="btn btn-warning" onclick="window.location.href='report_diy.php'">รายงานปัญหาจากของปลอม</button>
                    </div>
                </div>
            </div>

            <div id="employee_table" class="table-responsive">
            <table class="table table-hover table-bordered">
                <thead>
                    <tr>
                        <th>ลำดับ</th>
                        <th>รุ่นDrum</th>
                        <th>สาขา</th>
                        <th>ชื่อสาขาใหญ่</th>
                        <th>ชื่อสาขา/กดปริ้น</th>
                        <th>วันที่ลง</th>
                        <th>คนรับงาน</th>
                        <th>ส่งให้สาขา</th>
                        <?php if ($rs6["l_status"] == 1): ?>
                        <th>ลบ</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $a = 0; 
                    $i = 1;
                    while ($rs = mysqli_fetch_assoc($nquery)):
                        $sql3 = "SELECT * FROM `login` WHERE l_id=" . (int)$rs['re_l_id'];
                        $result3 = mysqli_query($conn, $sql3);
                        $rs3 = mysqli_fetch_assoc($result3);

                        $sql5 = "SELECT * FROM address WHERE b_id=" . (int)$rs['b_id'];
                        $result5 = mysqli_query($conn, $sql5);
                        $f5 = mysqli_fetch_assoc($result5);

                        $rowClass = ($a % 2 == 0) ? 'info' : '';
                    ?>
                    <tr class="<?php echo $rowClass; ?>">
                        <td><?php echo $i; ?></td>
                        <td><?php echo htmlspecialchars($rs[""]); ?></td>
                        <td><?php echo htmlspecialchars($rs["b_id"]); ?></td>
                        <td><?php echo htmlspecialchars($f5["a_name"]); ?></td>
                        <td>
                            <a href="test.php?b_id=<?php echo urlencode($rs['b_id']); ?>&re_diy_name=<?php echo urlencode($rs['re_diy_name']); ?>" target="_blank">
                                <?php echo htmlspecialchars($rs["re_diy_name"]); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($rs["re_diy_day"]); ?></td>
                        <td><?php echo htmlspecialchars($rs3["l_name"]); ?></td>
                        <td>
                            <?php if ($rs["re_diy_poin"] == 0): ?>
                                <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#add_data_HDD<?php echo $i; ?>">ส่ง</button>
                            <?php else: ?>
                                <span class="label label-success">ส่งให้สาขาแล้ว</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($rs6["l_status"] == 1): ?>
                        <td>
                            <a href="frm_diy_del.php?re_diy_id=<?php echo $rs['re_diy_id']; ?>&id=<?php echo $id; ?>" onclick="return confirm('คุณต้องการลบรายการนี้หรือไม่?');" title="ลบ">
                                <span class="glyphicon glyphicon-trash text-danger"></span>
                            </a>
                        </td>
                        <?php endif; ?>
                    </tr>

                    <!-- Modal ส่ง HDD ให้สาขา -->
                    <div id="add_data_HDD<?php echo $i; ?>" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="modalLabel_HDD<?php echo $i; ?>">
                      <div class="modal-dialog" role="document">
                        <div class="modal-content">
                          <?php if ($rs["d_sn"] != ""): ?>
                          <div class="modal-header bg-primary">
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                            <h4 class="modal-title">ส่ง HDD</h4>
                          </div>
                          <div class="modal-body">
                            <form class="ajax-form" action="edit_diy1.php" method="post" novalidate>
                              <p>ยืนยันการส่งให้สาขา <strong><?php echo htmlspecialchars($rs['b_id'] . " " . $rs['re_diy_name']); ?></strong></p>
                              <input type="hidden" name="re_diy_id" value="<?php echo $rs['re_diy_id']; ?>">
                              <div class="text-center mb-3">
                                <img src="images/NO_SN.png" width="50" height="100" alt="No SN" />
                              </div>
                              <button type="submit" class="btn btn-success btn-block">ยืนยัน</button>
                            </form>
                          </div>
                          <div class="modal-footer">
                            <button type="button" class="btn btn-default" data-dismiss="modal">ปิด</button>
                          </div>
                          <?php else: ?>
                          <div class="modal-header bg-danger">
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                            <h4 class="modal-title">กรุณาใส่ SN HDD ก่อน</h4>
                          </div>
                          <div class="modal-body text-center">
                            <img src="images/484070.jpg" class="img-responsive center-block" alt="No SN Image" />
                          </div>
                          <div class="modal-footer">
                            <button type="button" class="btn btn-default" data-dismiss="modal">ปิด</button>
                          </div>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>

                    <?php
                    $a++;
                    $i++;
                    endwhile;
                    ?>
                </tbody>
            </table>
            </div>
        </main>
    </div>
</div>



<!-- Modal เบิก Drum ส่งให้สาขา -->
<div id="add_data_Modal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="modalLabel_diy">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header bg-primary">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title">กรอกสาขาที่จะเบิก</h4>
      </div>
      <div class="modal-body">
        <form class="ajax-form" action="save_drum.php" method="post" novalidate>
          <div class="form-group">
            <label>พนักงานผู้เบิกของ <?php echo htmlspecialchars($rs6['l_name']); ?></label>
            <input type="hidden" name="l_id" value="<?php echo $id; ?>">
          </div>
          <div class="form-group">
            <label>รหัสสาขาใหญ่</label>
            <input name="b_id" type="text" id="b_id" onkeypress="check_key_number(event);" value="000" class="form-control" required>
          </div>
          <div class="form-group">
            <label for="bry_id">ชื่อสาขา</label>
            <select name="bry_id" disabled id="bry_id" class="form-control" required>
              <option value="">==>กรุณาเลือกสาขา<==</option>
            </select>
          </div>
          <div class="form-group">
            <label>รุ่นDrum</label>
            <select name="re_drum_number" id="re_drum_number" class="form-control" required>
              <option value="0">==>กรุณาเลือกDrum<==</option>
              <option value="1">=>Drum DR-3455(BT5600/BT5900)<=</option>
              <option value="2">=>Drum DR-3608(BT5915)<=</option>
            </select>
          </div>
          <div class="form-group">
            <label>วันที่บันทึกข้อมูล</label>
            <input name="re_diy_day" type="text" id="dateInput" value="<?php echo date('Y-m-d'); ?>" class="form-control" required>
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

</body>
</html>

 