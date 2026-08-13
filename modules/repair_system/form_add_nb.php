<?php
// session_start();
 date_default_timezone_set("Asia/Bangkok");
//$id =$_SESSION['id'];
//$pass= $_SESSION['pass'];
//if(isset($_SESSION["id"]) && isset($_SESSION["pass"])){
$a_id=$_GET["a_id"];
 $date = date("Y-m-d");
 include "connect_mtc.php";
 $sql="SELECT * FROM `admin` WHERE a_id=$id" ;
$result=mysqli_query($conn,$sql,);
$rs=mysqli_fetch_array($result);
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<style type="text/css">
body {
	background-color: #D6D6D6;
}
</style>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
<title>เพิ่มข้อมูล License Windows + office</title>
 
<link rel="stylesheet" media="all" type="text/css" href="jquery-ui.css" />
<link rel="stylesheet" media="all" type="text/css" href="jquery-ui-timepicker-addon.css" />
<script type="text/javascript" src="jquery-1.10.2.min.js"></script>
<script type="text/javascript" src="jquery-ui.min.js"></script>
<script type="text/javascript" src="jquery-ui-timepicker-addon.js"></script>
<script type="text/javascript" src="jquery-ui-sliderAccess.js"></script>

<script>
$(function(){
	$("#sa_id").change(function(){
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
$(function(){
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

$(function(){
	$("#dateInput").datepicker({
		dateFormat: 'yy-m-dd'
	});
});

</script>
<script>

function gohome(){

document.location.href='show_NB.php';
}
</script>

<script language="JavaScript" type="text/JavaScript">
function check_key_number() {
use_key=event.keyCode
if (use_key != 13 && (use_key < 48) || (use_key > 57)) {
event.returnValue = false;
}
}
</script>

<body>
<form id="form1" name="form1" method="post" action="save_nb.php">
  <table width="600" height="322" border="1" align="center" cellpadding="0" cellspacing="0">
    <tr>
      <td height="40" colspan="4" align="center" bgcolor="#FFFFCC">เพิ่มข้อมูล License Windows + office
      <input name="a_id" type="hidden" id="$id" value="<? echo $id?>" /></td>
    </tr>
    <tr>
      <td height="31" bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;รหัสผู้รับเครื่องเข้าระบบ</td>
      <td bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;&nbsp;<input name="l_id" type="text" id="l_id" onkeypress="check_key_number();" size="40" /></td>
    </tr>
    <tr>
      <td width="128" height="31" bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;SN Notebook</td>
      <td bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;&nbsp;<input name="nb_sn_nb" type="text" id="nb_sn_nb" size="40" /></td>
      
    </tr>
    <tr>
      <td height="29" bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;ชื่อคนใช้งานตัวเครื่อง</td>
      <td bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;&nbsp;<input name="nb_name" type="text" id="nb_name" size="40" /></td>
    </tr>
    <tr>
      <td height="32" valign="middle" bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;Mail_office</td>
      <td bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;&nbsp;<input name="nb_email" type="text" id="nb_email" size="40" /></td>
    </tr>
    <tr>
      <td height="33" bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;รหัสเข้า Mailoffice</td>
      <td bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;&nbsp;<input name="nb_pass_email" type="text" id="nb_pass_email" size="40" /></td>
    </tr>
    <tr>
      <td height="29" bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;Keyoffice</td>
      <td bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;&nbsp;<input name="nb_key_off" type="text" id="nb_key_off" size="40" /></td>
			
    </tr>
    <tr>
      <td height="33" bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;Carton_NO</td>
      <td bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;&nbsp;<input name="nb_cn_win" type="text" id="nb_cn_win" size="40" /></td>
     
    </tr>
    <tr>
    <td height="29" bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;วันที่ลงข้อมูล</td>
      <td bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;&nbsp;<input name="nb_day" type="text" id="dateInput" value="0000-00-00" /></td>
    </tr>
    <tr>
      <td height="36" colspan="4" align="center" bgcolor="#FFFFCC"><label for="r_waste"></label>
      <input type="submit" name="button" id="button" value="บันทึกข้อมูล" />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
      <input type="button" name="button2" id="button2" value="กลับ" onclick="gohome()" /></td>
    </tr>
  </table>
</form>
</body>
</html>
<?php
            // }
 						mysqli_close($conn);
//}
//else{
//	echo"<script> alert('loginก่อน'); window.location='index.php';</script>";
//	exit();
//}
?>