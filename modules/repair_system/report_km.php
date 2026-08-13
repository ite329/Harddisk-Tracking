<?php
include "connect_mtc.php";

$day1=$_POST["day1"];
$day2=$_POST["day2"];
if($day1!=""&&$day2!=""){
$sql="SELECT * FROM `keyboard_mouse_diy` WHERE km_day2 between '$day1'and '$day2'" ;			
$nquery=mysqli_query($conn,$sql);
}
else{
    $sql="SELECT * FROM `keyboard_mouse_diy` WHERE km_poin2 ='1'";
    $nquery=mysqli_query($conn,$sql);
}
?>
<!DOCTYPE html>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link rel="stylesheet" media="all" type="text/css" href="jquery-ui.css" />
<link rel="stylesheet" media="all" type="text/css" href="jquery-ui-timepicker-addon.css" />

<script type="text/javascript" src="jquery-1.10.2.min.js"></script>
<script type="text/javascript" src="jquery-ui.min.js"></script>

<script type="text/javascript" src="jquery-ui-timepicker-addon.js"></script>
<script type="text/javascript" src="jquery-ui-sliderAccess.js"></script>

<script type="text/javascript">

$(function(){
	$("#dateInput").datepicker({
		dateFormat: 'yy-mm-dd'
	});
});

</script>
<script type="text/javascript">

$(function(){
	$("#dateInput2").datepicker({
		dateFormat: 'yy-mm-dd'
	});
});

</script>


<title>รายงานที่เบิก</title>

    <link href="/harddisk_delivery_web/assets/css/hdd-sarabun-font.css?v=20260727" rel="stylesheet">
</head>
<body>
<div align="center">
    <form id="form" name="form" method="post"  action="report_diy.php">
                <h1>รายงาน Keyboard&Mouse ที่มีการเบิกไปใช้งาน</h1>
        <table width="1028" height="100" border="1" cellpadding="0" cellspacing="0">

             <tr>
              เลือกช่วงเวลาที่จะออก:
              <label for="day1"></label>
                &nbsp;
                <input name="day1" type="text" id="dateInput" value="0000-00-00" size="10" />
                &amp;&amp;
                <label for="day2"></label>
                <input name="day2" type="text" id="dateInput2" value="0000-00-00" size="10" />
                กดออกรายงาน:
              &nbsp;  
            </tr>
            <input type="submit" name="button" id="button" value="ค้นหา" />

            <tr align="center" bgcolor="#FFCC99">
                <td>ลำดับ</td>
                <td>ฝ่าย</td>
                <td>ชื้อคนรับ</td>
                <td>SN </td>
                <td>วันที่เบิก</td>
                <td>ชื่อคนเบิก</td>
                <td>ชุดอุปกรณ์</td>
            </tr>
            <?php
            $num=1;
            while($rs = mysqli_fetch_array($nquery)){

                $sql2="SELECT * FROM `login` WHERE l_id=$rs[km_l_id2] " ;			
                $nq=mysqli_query($conn,$sql2);
                $rs2 = mysqli_fetch_array($nq);

                $G1="SELECT * FROM `group_ps_center` WHERE group_id=$rs[group_id] " ;			
                $GO1=mysqli_query($conn,$G1);
                $GOP1 = mysqli_fetch_array($GO1);
                ?>
            <tr>
                <td align="center"><?=$num ?></td>
                <td align="center"><?=$GOP1["group_name"]?></td>
                <td>&nbsp;&nbsp;<?=$rs["km_name"]?></td>
                <td align="center"><?=$rs["km_sn"]?></td>
                <td align="center"><?=$rs["km_day2"]?></td>
                <td align="left">&nbsp;&nbsp;<?=$rs2["l_name"]?></td>
                <td align="left">&nbsp;&nbsp;<?php if($rs["km_poin"]==1){ echo "2IN1 LOGITECH ";}
                                                    if($rs["km_poin"]==2){ echo "Mouse ";}
                
                
                ?></td>
            </tr>
            <?php
            $num++;
            }
            ?>
        </table>
        <div align="center">
        <button type="button" onclick="window.location.href='show_com_re.php'" class="btn btn-warning">กลับ</button>
        </div>
        </form>
</div>
</body>
</html>