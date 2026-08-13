<?php  
session_start();

if (!isset($_SESSION['id']) || !isset($_SESSION['pass'])) {
    echo "<script>alert('กรุณา login ก่อน'); window.location='login1.php';</script>";
    exit();
}

$id = $_SESSION['id'];
$pass = $_SESSION['pass'];

include "connect_mtc.php";

$nb_sn_nb = $_POST["nb_sn_nb"] ?? "";

// Pagination
if ($nb_sn_nb == "") {
    $query = mysqli_query($conn, "SELECT COUNT(nb_id) FROM `notebook`");
    $row = mysqli_fetch_row($query);
    $rows = $row[0];
    $page_rows = 15;
    $last = ceil($rows / $page_rows);
    if ($last < 1) $last = 1;

    $pagenum = 1;
    if (isset($_GET['pn'])) {
        $pagenum = preg_replace('#[^0-9]#', '', $_GET['pn']);
    }
    if ($pagenum < 1) $pagenum = 1;
    elseif ($pagenum > $last) $pagenum = $last;

    $limit = 'LIMIT ' . (($pagenum - 1) * $page_rows) . ',' . $page_rows;
    $nquery = mysqli_query($conn, "SELECT * FROM notebook $limit");

    $paginationCtrls = '';
    if ($last != 1) {
        if ($pagenum > 1) {
            $paginationCtrls .= '<a href="'.$_SERVER['PHP_SELF'].'?pn=1" class="btn btn-info">หน้าแรก</a> &nbsp;';
            $previous = $pagenum - 1;
            $paginationCtrls .= '<a href="'.$_SERVER['PHP_SELF'].'?pn='.$previous.'" class="btn btn-info">ก่อนหน้า</a> &nbsp;';
            for ($i = $pagenum - 4; $i < $pagenum; $i++) {
                if ($i > 0) {
                    $paginationCtrls .= '<a href="'.$_SERVER['PHP_SELF'].'?pn='.$i.'" class="btn btn-primary">'.$i.'</a> &nbsp;';
                }
            }
        }
        $paginationCtrls .= '<span class="btn btn-default">'.$pagenum.'</span> &nbsp;';
        for ($i = $pagenum + 1; $i <= $last; $i++) {
            $paginationCtrls .= '<a href="'.$_SERVER['PHP_SELF'].'?pn='.$i.'" class="btn btn-primary">'.$i.'</a> &nbsp;';
            if ($i >= $pagenum + 4) break;
        }
        if ($pagenum != $last) {
            $next = $pagenum + 1;
            $paginationCtrls .= '<a href="'.$_SERVER['PHP_SELF'].'?pn='.$next.'" class="btn btn-info">ถัดไป</a> &nbsp;';
            $paginationCtrls .= '<a href="'.$_SERVER['PHP_SELF'].'?pn='.$last.'" class="btn btn-info">สุดท้าย</a> ';
        }
    }
} else {
    $sql = "SELECT * FROM `notebook` WHERE nb_sn_nb LIKE '%$nb_sn_nb%' OR nb_name LIKE '%$nb_sn_nb%'";
    $nquery = mysqli_query($conn, $sql);
    $paginationCtrls = ''; // No pagination for search results
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8" />
<title>ข้อมูล License Windows + Microsoft Office</title>

<!-- Bootstrap CSS -->
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" />

<!-- jQuery UI CSS -->
<link rel="stylesheet" href="jquery-ui.css" />
<link rel="stylesheet" href="jquery-ui-timepicker-addon.css" />

<style>
    body {
        background-color: #D6D6D6;
        font-family: Tahoma, Geneva, sans-serif;
        font-size: 14px;
        color: #333;
        padding: 20px 0;
    }
    .container-main {
        background-color: #fff;
        padding: 20px 25px;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        max-width: 1100px;
        margin: auto;
    }
    h2.title {
        font-weight: bold;
        margin-bottom: 25px;
        text-align: center;
        color: #28a745;
    }
    /* เมนูซ้าย */
    .col-md-3 {
        background-color: #e9f7ef;
        padding: 15px;
        border-radius: 8px;
        box-shadow: inset 0 0 5px rgba(0,0,0,0.1);
        min-height: 600px;
    }
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
    /* เนื้อหาขวา */
    .col-md-9 {
        background-color: #fff;
        padding: 25px 20px;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    .search-panel {
        background-color: #d4edda;
        border: 1px solid #c3e6cb;
        padding: 15px 20px;
        border-radius: 6px;
        margin-bottom: 20px;
    }
    .search-panel input[type="text"] {
        width: 250px;
        display: inline-block;
        margin-right: 10px;
    }
    table {
        width: 100%;
        border-collapse: collapse;
    }
    table th {
        background-color: #FFCC66;
        text-align: center;
        font-size: 14px;
        padding: 8px;
        border: 1px solid #ddd;
    }
    table td {
        font-size: 13px;
        padding: 8px;
        border: 1px solid #ddd;
        vertical-align: middle;
    }
    tr:nth-child(even) {
        background-color: #d9f0d9;
    }
    tr:nth-child(odd) {
        background-color: #ccf5cc;
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
        z-index: 1000;
    }
    /* Buttons */
    .btn-warning {
        font-weight: 600;
    }
</style>

<!-- jQuery & Bootstrap JS -->
<script src="https://ajax.googleapis.com/ajax/libs/jquery/2.2.0/jquery.min.js"></script>
<script src="jquery-ui.min.js"></script>
<script src="jquery-ui-timepicker-addon.js"></script>
<script src="jquery-ui-sliderAccess.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/js/bootstrap.min.js"></script>

<script>
$(document).ready(function(){
    // Tooltip ajax
    $('.lnk').hover(function(e){
        $('body').append('<div class="showTooltip"></div>');
        var showTooltip = $('.showTooltip');
        $.ajax({
            url: $(this).attr('turl') + '&' + new Date().getTime(),
            beforeSend: function(){
                showTooltip.html('<img src="wait.gif"/>');
            },
            success: function(data){
                showTooltip.html(data);
            }
        });
        var mousex = e.pageX + 20;
        var mousey = e.pageY - 200;
        var tooltipWidth = showTooltip.width();
        var tooltipHeight = showTooltip.height();
        var toolVisX = $(window).width() - (mousex + tooltipWidth);
        var toolVisY = ($(window).height() + $(window).scrollTop()) - (mousey + tooltipHeight);
        if (toolVisX < 10) mousex = e.pageX - tooltipWidth - 40;
        if (toolVisY < 10) mousey = e.pageY - tooltipHeight - 10;
        showTooltip.css({top: mousey, left: mousex, display: 'none'});
        showTooltip.slideDown('slow');
    }, function(){
        $('.showTooltip').remove();
    });

    // Datepicker
    $("#dateInput").datepicker({
        dateFormat: 'yy-m-dd'
    });
});

// ป้องกันกรอกเฉพาะตัวเลข
function check_key_number() {
    var use_key = event.keyCode;
    if (use_key != 13 && (use_key < 48 || use_key > 57)) {
        event.returnValue = false;
    }
}

</script>

    <link href="/harddisk_delivery_web/assets/css/hdd-sarabun-font.css?v=20260727" rel="stylesheet">
</head>
<body>
<div class="container container-main">
    <h2 class="title">รายการ License Windows and Microsoft Office ประจำเครื่อง</h2>
    <div class="row">
        <div class="col-md-3">
            <ul class="nav nav-pills nav-stacked" style="margin-top: 15px;">
                <li><a href="index.php">1. หน้าเช็คทรัพย์สิน</a></li>
                <li><a href="server.php">2. เครื่อง server</a></li>
                <li><a href="system_information.php">3. ข้อมูลระบบไอที</a></li>
                <li><a href="show_software.php">4.Software License</a></li>
                <li><a href="report2.php">5. ปริ้นที่อยุ่ส่งสาขา</a></li>
                <li><a href="show_NB.php">6. ข้อมูล License NB</a></li>
                <li><a href="show_com_re.php">7. Keyboard & Mouse</a></li>
                <li><a href="show_drum.php">8. เบิกDrum</a></li>
                <li><a href="show_diy_repair.php">9. ส่งอุปกรณ์ HDD</a></li>
                <li><a href="show_del_computer.php">10. ลบเครื่อง Joindomain</a></li>
                <li><a href="../serial_computer/show_sncom.php" target="_blank" rel="noopener">11. ข้อมูลคอมพิวเตอร์</a></li>
                <li><a href="logout.php">ออกจากระบบ</a></li>
            </ul>
        </div>

        <div class="col-md-9">
            <div class="search-panel form-inline">
                <form id="form1" name="form1" method="post" action="show_NB.php" class="form-inline">
                    <input type="text" name="nb_sn_nb" id="nb_sn_nb" class="form-control" placeholder="ค้นหาเครื่อง..." value="<?php echo htmlspecialchars($nb_sn_nb); ?>" />
                    <button type="submit" class="btn btn-success">ค้นหา</button>
                    <button type="button" name="add" id="add" data-toggle="modal" data-target="#add_data_Modal" class="btn btn-warning">เพิ่มข้อมูล License</button>
                </form>
            </div>

            <table class="table table-bordered table-hover">
                <thead>
                    <tr>
                        <th>ลำดับ</th>
                        <th>ฝ่าย</th>
                        <th>ผู้ใช้งานตัวเครื่อง</th>
                        <th>Serial Number</th>
                        <th>วันที่บันทึกข้อมูล</th>
                        <th>รายละเอียด</th>
                        <th>แก้ไข</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $a = 0; $i = 1;
                while($crow = mysqli_fetch_array($nquery)){
                    $sql1 = "SELECT * FROM `group_ps_center` WHERE group_id = {$crow['group_id']}";
                    $result1 = mysqli_query($conn, $sql1);
                    $rs1 = mysqli_fetch_array($result1);

                    $sql2 = "SELECT * FROM `login` WHERE l_id = {$crow['l_id']}";
                    $result2 = mysqli_query($conn, $sql2);
                    $rs2 = mysqli_fetch_array($result2);

                    $bgcolor = ($a % 2 == 0) ? '#3f9' : '#CCFFFF';
                ?>
                    <tr style="background-color: <?php echo $bgcolor; ?>;">
                        <td class="text-center"><?php echo $crow["nb_id"]; ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($rs1["group_name"]); ?></td>
                        <td class="text-center">
                            <span class="lnk" data-toggle="tooltip" title="ดูรายละเอียดเพิ่มเติม" style="cursor:pointer;" data-target="#detail<?=$i?>" data-toggle="modal">
                                <?php echo htmlspecialchars($crow["nb_name"]); ?>
                            </span>
                        </td>
                        <td class="text-center"><?php echo htmlspecialchars($crow["nb_sn_nb"]); ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($crow["nb_day"]); ?></td>
                        <td class="text-center">
                            <img src="images/addressbook.ico" width="30" height="30" style="cursor:pointer;" data-toggle="modal" data-target="#detail<?=$i?>" />
                        </td>
                        <td class="text-center">
                            <a href="form_edit_nb.php?nb_id=<?php echo $crow['nb_id']; ?>">
                                <img src="images/edit.ico" width="30" height="30" alt="แก้ไข" />
                            </a>
                        </td>
                    </tr>

                    <!-- Modal รายละเอียด -->
                    <div class="modal fade" id="detail<?=$i?>" tabindex="-1" role="dialog" aria-labelledby="detailLabel<?=$i?>" aria-hidden="true" data-backdrop="static" data-keyboard="false">
                        <div class="modal-dialog modal-lg" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                                    <h4 class="modal-title" id="detailLabel<?=$i?>">รายละเอียด</h4>
                                </div>
                                <div class="modal-body">
                                    <div class="panel panel-default" style="background-color: #fffdef;">
                                        <div class="panel-body">
                                            <p><strong>SN Notebook:</strong> <?=$crow['nb_sn_nb']?></p>
                                            <p><strong>วันที่บันทึกข้อมูล:</strong> <?=$crow['nb_day']?></p>
                                            <p><strong>ผู้บันทึกข้อมูล:</strong> <?=$rs2['l_name']?></p>
                                            <p><strong>ชื่อผู้ใช้งานตัวเครื่อง:</strong> <?=$crow['nb_name']?></p>
                                            <p><strong>E-mail Microsoft Office:</strong> <?=$crow['nb_email']?></p>
                                            <p><strong>รหัส E-mail Microsoft Office:</strong> <?=$crow['nb_pass_email']?></p>
                                            <p><strong>Key Microsoft Office:</strong> <?=$crow['nb_key_off']?></p>
                                            <p><strong>ใบ PO:</strong> <?=$crow['nb_cn_win']?></p>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-default" data-dismiss="modal">ปิด</button>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php
                $a++;
                $i++;
                } ?>
                </tbody>
            </table>

            <div class="text-center" id="pagination_controls"><?php echo $paginationCtrls; ?></div>
        </div>
    </div>
</div>

<!-- Modal เพิ่มข้อมูล -->
<div id="add_data_Modal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="addDataLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form id="insert_form" action="save_nb.php" method="post" enctype="multipart/form-data" class="form-horizontal">
        <div class="modal-header">
          <button type="button" class="close" data-dismiss="modal">&times;</button>
          <h4 class="modal-title" id="addDataLabel">เพิ่มข้อมูล License Windows and Microsoft Office</h4>
        </div>
        <div class="modal-body">
          <label>รหัสพนักงานผู้รับเครื่องเข้าระบบ</label>
          <input name="l_id" type="text" id="l_id" onkeypress="check_key_number();" class="form-control" />
          <br />
          <label>SN Notebook</label>
          <input name="nb_sn_nb" type="text" id="nb_sn_nb_modal" class="form-control" />
          <br />
          <label>ชื่อผู้ใช้งานตัวเครื่อง</label>
          <input name="nb_name" type="text" id="nb_name" class="form-control" />
          <br />
          <label>ฝ่าย</label>
          <select name="group_id" id="group_id" class="form-control">
            <option value="">==>กรุณาเลือกฝ่าย<==</option>
            <?php
            $sql11 = "SELECT * FROM `group_ps_center`";
            $q11 = mysqli_query($conn, $sql11);
            while ($f11 = mysqli_fetch_array($q11)) {
                echo '<option value="'.$f11["group_id"].'">'.htmlspecialchars($f11["group_name"]).'</option>';
            }
            ?>
          </select>
          <br />
          <label>E-mail Microsoft Office</label>
          <input name="nb_email" type="text" id="nb_email" class="form-control" />
          <br />
          <label>รหัส E-mail Microsoft Office</label>
          <input name="nb_pass_email" type="text" id="nb_pass_email" class="form-control" />
          <br />
          <label>Key Microsoft Office</label>
          <input name="nb_key_off" type="text" id="nb_key_off" class="form-control" />
          <br />
          <label>ใบ PO</label>
          <input name="nb_cn_win" type="text" id="nb_cn_win" class="form-control" />
          <br />
          <label>วันที่บันทึกข้อมูล</label>
          <input name="nb_day" type="text" id="dateInput" value="<?=date('Y-m-d')?>" class="form-control" />
          <br />
        </div>
        <div class="modal-footer">
          <input type="submit" name="insert" id="insert" value="เพิ่มข้อมูล" class="btn btn-success" />
          <button type="button" class="btn btn-default" data-dismiss="modal">ปิด</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
$(document).ready(function(){
    $('#insert_form').on("submit", function(event){
        event.preventDefault();
        // สามารถเพิ่มตรวจสอบข้อมูลก่อนส่งได้ที่นี่
        $.ajax({
            url: "save_nb.php",
            method: "POST",
            data: $('#insert_form').serialize(),
            beforeSend: function(){
                $('#insert').val("กำลังบันทึก...");
            },
            success: function(data){
                alert('เพิ่มข้อมูลเรียบร้อย');
                $('#insert_form')[0].reset();
                $('#add_data_Modal').modal('hide');
                location.reload(); // โหลดหน้าใหม่เพื่อแสดงข้อมูลล่าสุด
            }
        });
    });
});
</script>

</body>
</html>
