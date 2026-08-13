<?php  
include "connect_mtc.php";
$id=$_POST["id"];
                       if($id!=""){		
						 $sql1="SELECT * FROM repair where re_sn like '%$id%' or re_sn like '%$id%' ";
						                    $result1=mysqli_query($conn,$sql1);
					   }		
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link rel="stylesheet" media="all" type="text/css" href="jquery-ui.css" />
<link rel="stylesheet" media="all" type="text/css" href="jquery-ui-timepicker-addon.css" />
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/2.2.0/jquery.min.js"></script>  
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" />  
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/js/bootstrap.min.js"></script> 

<script type="text/javascript" src="jquery-ui.min.js"></script>
<script type="text/javascript" src="jquery-ui-timepicker-addon.js"></script>
<script type="text/javascript" src="jquery-ui-sliderAccess.js"></script>
<script type="text/javascript" src="../jquery.js"></script>
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
<title>โปรแกรมปริ้นที่อยู่สาขาย่อย</title>
<style type="text/css">
a:link {
	text-decoration: none;
}
a:visited {
	text-decoration: none;
}
a:hover {
	text-decoration: none;
}
a:active {
	text-decoration: none;
}
body {
	background-color: #D6D6D6;
}
</style>
</script>

<style type="text/css">
/*ปรับสีสันของ Tooltip ได้จากคำสั่ง CSS ตรงนี้*/
body{
	font-size: 12px;
	font-family: Tahoma, Geneva, sans-serif;
	color: #E1E1E1;
	background-color: #CCCCCC;
}
.showTooltip{
float:left;
padding:10px;
background:#F3F3F3;
border:2px solid #CFCFCF;
-moz-border-radius: 4px;  
-webkit-border-radius: 4px;  
border-radius: 4px;
color:#333;
position:absolute;
}
a{
margin:5px;
color:#06C;
text-decoration:none;
}
body,td,th {
	color: #000000;
}
</style>
</style>
<script language="JavaScript" type="text/JavaScript">
function check_key_number() {
use_key=event.keyCode
if (use_key != 13 && (use_key < 48) || (use_key > 57)) {
event.returnValue = false;
}
}
</script>

<body>
<form id="form1" name="form1" method="post" action="wcs.php">
<div id="employee_table">
  <table width="887" height="243" border="1" align="center" cellpadding="0" cellspacing="0" dir="ltr">
    <tr>
      <td height="58" colspan="2" bgcolor="#FFFFFF" style="text-align: center; font-size: 18px; font-weight: bold;">ค้นหาที่อยู่ที่จะปริ้นส่ง</td>
    </tr>
    <tr>
      <td width="201" height="183" valign="top" bgcolor="#FFFFFF"><table width="201" height="181" border="0" cellpadding="0" cellspacing="0">
        <tr>
          <td align="center" style="font-weight: bold">MENU</td>
        </tr>
        <tr>
          <td><span style="text-align: center; font-size: 16px;"><a href="index.php">&nbsp;1.หน้าเช็คทรัพย์สิน</a></span></td>
        </tr>
        <tr>
          <td style="font-size: 16px"><a href="wcs.php">&nbsp;2.อุปกรณ์ส่งมาซ่อมWCS</a></td>
        </tr>
        <tr>
          <td style="font-size: 16px"><a href="report1.php">&nbsp;3.ปริ้นที่อยุ่ส่งสาขาใหญ่</a></td>
        </tr>
        <tr>
          <td style="font-size: 16px"><a href="report2.php">&nbsp;4.ปริ้นที่อยุ่ส่งสาขาย่อย</a></td>
        </tr>
        <tr>
          <td style="font-size: 16px"><a href="show_NB.php">&nbsp;5.ข้อมูล License</a></td>
        </tr>
        <tr>
          <td height="23" style="font-size: 14px"></td>
        </tr>
        <tr>
          <td height="23" style="font-size: 14px"></td>
        </tr>
      </table></td>
      <td width="680" valign="top" bgcolor="#FFFFFF">
      <table align="center" width="676" border="1" cellspacing="0" cellpadding="0">
		<tr>
         <td align="center" bgcolor="cyan" colspan="5">S/N เครื่องปริ้นเตอร์ <input type="text" name="id" id="id" value="<?php echo $f["b_id"]?>" size="15"/>&nbsp;&nbsp;&nbsp;
		                                        <input type="submit" name="button" id="button" value="ค้นหา" />
											
											</td>
        </tr>
          <tr>
          <td width="76" height="35" align="center" bgcolor="lime" style="font-size: 14px;">วันที่</td>
          <td width="200" align="center" bgcolor="lime" style="font-size: 14px">ร้านซ่อม</td>
          <td width="200" align="center" bgcolor="lime" style="font-size: 14px">S/N</td>
          <td width="100" align="center" bgcolor="lime" style="font-size: 14px">ราคา</td>
         
          
          </tr><?php 
					while($f=mysqli_fetch_array($result1)){

						if($a%2==0){ $b="#3f9";}
						else{$b="#CCFFFF";}	
						?>
        <tr bgcolor="<?php echo $b ;?>">
          <td height="37" align="center" style="font-size: 12px">&nbsp;<?php echo $f["re_day"];?></td>
          <td align="life" style="font-size: 12px">&nbsp;<?php  echo $f["re_wcs"] 	?></td>
          <td align="center" style="font-size: 12px">&nbsp;<?php echo $f["re_sn"] ?></td>
          <td align="center" style="font-size: 12px">&nbsp;<?php echo $f["re_price"];?></td>
          
          </tr> <?php
				$a++;	
					 } 
 						mysqli_close($conn);
			?>
    </table></td>
    </tr>
  </table>
  </form>

</body>
</html>

<?php
//}
//else{
	//echo"<script> alert('loginก่อน'); window.location='index.php';</script>";
//	exit();
//}
?>