<?php
session_start();
 date_default_timezone_set("Asia/Bangkok");
$id =$_SESSION['id'];
$pass= $_SESSION['pass'];
if(isset($_SESSION["id"]) && isset($_SESSION["pass"])){
 include "connect_mtc.php";
 
 $r = " SELECT * FROM `keyboard_mouse_diy` ";
 $q = mysqli_query( $conn, $r);
 $num = mysqli_num_rows( $q );

 $sql="SELECT * FROM `keyboard_mouse_diy` order BY km_id DESC";
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
<title>ลงบันทึก</title>

<link rel="stylesheet" media="all" type="text/css" href="jquery-ui.css" />
<link rel="stylesheet" media="all" type="text/css" href="jquery-ui-timepicker-addon.css" />
<script type="text/javascript" src="jquery-1.10.2.min.js"></script>
<script type="text/javascript" src="jquery-ui.min.js"></script>
<script type="text/javascript" src="jquery-ui-timepicker-addon.js"></script>
<script type="text/javascript" src="jquery-ui-sliderAccess.js"></script>


<body onLoad="setFocus()">
<form  neme="frm" id="insert_form" action="edit_km.php" class="form-horizontal" method="post" enctype="multipart/form-data">
              
 <table width="600" height="322" border="1" align="center" cellpadding="0" cellspacing="0">
        <tr>
      <td height="40" colspan="4" align="center" bgcolor="#FFFFCC">เพิ่มข้อมูลการเบิกอุปกรณ์
      <input name="l_id" type="hidden" id="l_id" value="<?=$id ?>" /></td>
    </tr>
    <tr>
      <td height="31" bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;ผู้เบิกเครื่อง</td>
      <td bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;&nbsp;<?php 
      $sql6="SELECT * FROM `login` WHERE l_id=$id " ;			
      $na=mysqli_query($conn,$sql6);
      $rs6= mysqli_fetch_array($na);
      
      
      echo $rs6["l_name"];
      
      ?></td>
    </tr>
    <tr>
      <td width="128" height="31" bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;S/N</td>
      <td bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;&nbsp;<input name="km_sn" type="text" id="km_sn" size="40" /></td>
      
    </tr>
    <tr>
      <td height="29" bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;ชื่อคนใช้งานตัวเครื่อง</td>
      <td bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;&nbsp;<input name="km_name" type="text" id="km_name" size="40" /></td>
    </tr>
    <tr>
    <td height="29" bgcolor="#FFFFCC" style="font-size: 12px">&nbsp;ให้ฝ่าย</td>
    <td height="29" bgcolor="#FFFFCC" style="font-size: 12px">
    <select name="group_id" id="group_id"  class="form-control">
                      <option value="">==>กรุณาเลือกฝ่าย<==</option>
                      <?php
             	               $sql11=" SELECT * FROM `group_ps_center` " ;
				                      $q11=mysqli_query($conn,$sql11);
			                        while ($f11=mysqli_fetch_array($q11)){
			                        ?>
                      <option value="<?php echo $f11["group_id"]?>"><?php echo $f11["group_name"]?></option>
                           <?php
                              } 
 						 
			                      ?>
                     </select> 
                            </td>
                            </tr>
    <tr>
      <td height="36" colspan="4" align="center" bgcolor="#FFFFCC"><label for="r_waste"></label>
      <input type="submit" name="button" id="button" value="บันทึกข้อมูล" />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
      <input type="button" name="button2" id="button2" value="กลับ" onclick="location.href='show_com_re.php'" /></td>
    </tr>
        </table>
        </form>
</body>
</html>
<?php
            
 						mysqli_close($conn);

                            }
                            else{
    	echo"<script> alert('loginก่อน'); window.location='index.php';</script>";
exit();
}
?>