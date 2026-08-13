<?php  session_start();
$id =$_SESSION['id'];
$pass= $_SESSION['pass'];
if(isset($_SESSION["id"]) && isset($_SESSION["pass"])){
include "connect_mtc.php";
//$id=$_POST["id"];
//$a_re=$_POST["a_re"];
//echo $a_re;
                   
                       $sql1="SELECT * FROM computer_repair where  co_poin=0 ";			
		                   $result1=mysqli_query($conn,$sql1);
			
                       if(isset($_GET['act'])){
                        if($_GET['act']== 'excel'){
                          header("Content-Type: application/xls");
                          header("Content-Disposition: attachment; filename=export.xls");
                          header("Pragma: no-cache");
                          header("Expires: 0");
                        }
                      }			
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">
<head>
<form id="form1" name="form1" method="post" action="report_computer.php">
<div class="container">
<table align="center" width="1000" border="1" cellspacing="0" cellpadding="0" class="table table-hover">
		<tr>
    <td align="center"  colspan="7">ทรัพย์สินที่อยู่สาขา</td>
        </tr>
          <tr>
          <td width="80" height="35" align="center"  style="font-size: 14px;">รหัสทรัพย์สินใหม่</td>
          <td width="80" height="35" align="center"  style="font-size: 14px;">รหัสทรัพย์สินเก่า</td>
          <td width="180" align="center"  style="font-size: 14px">ชื่อทรัพย์สิน</td>
          <td width="100" align="center"  style="font-size: 14px">วันรับทรัพย์เข้า</td>
          <td width="170" align="center"  style="font-size: 14px">รหัสสาขา</td>
          <td width="170" align="center"  style="font-size: 14px">ชื่อสาขา</td>
          <td width="170" align="center"  style="font-size: 14px">เขต</td>
          
          
          </tr><?php 
					while($f=mysqli_fetch_array($result1)){
						?>
          <tr >
          <td height="37" align="center" style="font-size: 12px">&nbsp;<?php echo $f["co_code_new"];?></td>
          <td align="center" style="font-size: 12px">&nbsp;<?php  echo $f["co_code_old"]; 	?></td>
          <td align="life" style="font-size: 12px"><?php echo $f["co_list"] ?></td>
          <td align="center" style="font-size: 12px">&nbsp;<?php echo $f["co_day"]; ?></td>
          <td align="center" style="font-size: 12px">&nbsp;<?php echo $f["co_id_branch"];?> </td>
          <td align="center" style="font-size: 12px">&nbsp;<?php echo $f["co_name_branch"];?> </td>
          <td align="center" style="font-size: 12px">&nbsp;<?php echo $f["co_kad"];?></td>
          
          </tr> 
          <?php
					 } 
 						mysqli_close($conn);
			?>
    </table>
          </div>
    </form>
    </body>
</html>

<?php
}
else{
	echo"<script> alert('loginก่อน'); window.location='login1.php';</script>";
exit();
}


?>