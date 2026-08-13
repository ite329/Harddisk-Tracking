<?php
// session_start();
 date_default_timezone_set("Asia/Bangkok");
//$id =$_SESSION['id'];
//$pass= $_SESSION['pass'];
//if(isset($_SESSION["id"]) && isset($_SESSION["pass"])){
 $nb_id=$_GET["nb_id"];
 $date = date("Y-m-d");
 include "connect_mtc.php";
 
 $r = " SELECT * FROM `diy` ";
 $q = mysqli_query( $conn, $r);
 $num = mysqli_num_rows( $q );

 $sql="SELECT * FROM `diy` order BY d_id DESC";
 $result=mysqli_query($conn,$sql);
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
<title>ลงบันทึก HDD</title>
<script>
function setFocus(){
frm.sn.focus();
}
</script>
<link rel="stylesheet" media="all" type="text/css" href="jquery-ui.css" />
<link rel="stylesheet" media="all" type="text/css" href="jquery-ui-timepicker-addon.css" />
<script type="text/javascript" src="jquery-1.10.2.min.js"></script>
<script type="text/javascript" src="jquery-ui.min.js"></script>
<script type="text/javascript" src="jquery-ui-timepicker-addon.js"></script>
<script type="text/javascript" src="jquery-ui-sliderAccess.js"></script>


<body onLoad="setFocus()">
<form id="form1" name="frm" method="post" action="save_diy_add.php">
  <table width="600" height="200" border="1" align="center" cellpadding="0" cellspacing="0">
    <tr>
      <td height="15" colspan="4" align="center" bgcolor="#FFFFCC">เพิ่มข้อมูล HDD เข้าระบบ <br>จำนวนที่มี <?php echo $num ?> ลูก</td>
    </tr>
    <tr>
      <td height="10" bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;บักทึกข้อมูล</td>
      <td bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;&nbsp;<input name="sn" type="text" id="sn" value="" size="40" /></td>
</tr>
    </tr>
    <tr>
    
      <td height="10" colspan="4" align="center" bgcolor="#FFFFCC"><?php echo  $rs["d_sn"]?><br/>
      <input type="submit" name="button" id="button" value="บันทึกข้อมูล" />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
      <input type="button" name="button2" id="button2" value="กลับ" onclick="location.href='show_diy_repair.php'" /></td>
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