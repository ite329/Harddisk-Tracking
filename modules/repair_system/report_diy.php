<?php
include "connect_mtc.php";

$day1=$_POST["day1"];
$day2=$_POST["day2"];
if($day1!=""&&$day2!=""){
$sql="SELECT * FROM `report_diy` WHERE re_diy_day2 between '$day1'and '$day2'" ;			
$nquery=mysqli_query($conn,$sql);
}
else{
    $sql="SELECT * FROM `report_diy` WHERE re_diy_poin ='1'";
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


<title>รายงาน HDD ที่ส่งแล้ว</title>

    <link href="/harddisk_delivery_web/assets/css/hdd-sarabun-font.css?v=20260727" rel="stylesheet">
</head>
<body>
<div align="center">
    <form id="form" name="form" method="post"  action="report_diy.php">
                <h1>รายงาน HDD ที่ส่งให้สาขาแล้ว</h1>
        <table width="900" height="100" border="1" cellpadding="0" cellspacing="0">

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
                <td>รหัสสาขา</td>
                <td>ชื้อสาขา</td>
                <td>SN_HDD</td>
                <td>วันที่ส่งให้สาขา</td>
                <td width="200" >คนแจ้ง</td>
            </tr>
            <?php
            $num=1;
            while($rs = mysqli_fetch_array($nquery)){

                $sql2="SELECT * FROM `login` WHERE l_id=$rs[re_l_id] " ;			
                $nq=mysqli_query($conn,$sql2);
                $rs2 = mysqli_fetch_array($nq);
                ?>
            <tr>
                <td align="center"><?=$num ?></td>
                <td align="center"><?=$rs["b_id"]?></td>
                <td>&nbsp;&nbsp;<?=$rs["re_diy_name"]?></td>
                <td align="center"><?=$rs["d_sn"]?></td>
                <td align="center"><?=$rs["re_diy_day2"]?></td>
                <td align="left">&nbsp;&nbsp;<?=$rs2["l_name"]?></td>
            </tr>
            <?php
            $num++;
            }
            ?>
        </table>
        <div align="center">
        <button type="button" onclick="window.location.href='show_diy_repair.php'" class="btn btn-warning">กลับ</button>
        </div>
        </form>
</div>
</body>
</html>