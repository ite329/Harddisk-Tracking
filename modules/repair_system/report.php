<?php  
include "connect_mtc.php";
$a_id=$_GET["a_id"];
$id=$_GET["as_id"] ;
                $sql = " SELECT * FROM asset where as_id=$id";
                $q = mysqli_query( $conn, $sql );
                $f = mysqli_fetch_assoc( $q );						
	                  //echo $f["as_name"];
  //echo $a_id;
$name=$f["as_name"];
         if($name!=""){
          $sql1="SELECT * FROM asset where as_name='$name'  " ;			
          $result1=mysqli_query($conn,$sql1);
         }
         if($a_id!=""){
          $sql1="SELECT * FROM asset where  as_name like '%$a_id%' " ;			
          $result1=mysqli_query($conn,$sql1);

         }
                       
			//$r=mysqli_fetch_assoc($result1);
			
			//echo $name;
										
				
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

<title>เก็บข้อมูลงานซ่อมอุปกรณ์</title>
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
<form id="form1" name="form1" method="post" action="">
<div id="employee_table">
        <table align="center" width="1000" border="1" cellspacing="0" cellpadding="0">
          <tr>
          <td width="76" height="35" align="center" bgcolor="#FFCC66" style="font-size: 14px;">รหัสทรัพย์สินใหม่</td>
          <td width="69" align="center" bgcolor="#FFCC66" style="font-size: 14px">รหัสเก่า</td>
          <td width="135" align="center" bgcolor="#FFCC66" style="font-size: 14px">รายการ</td>
          <td width="163" align="center" bgcolor="#FFCC66" style="font-size: 14px">สาขา</td>
          <td width="153" align="center" bgcolor="#FFCC66" style="font-size: 14px">วันที่ลงทรัพย์สิน</td>
          <td width="66" align="center" bgcolor="#FFCC66" style="font-size: 14px">ราคาค่าเสื่อม</td>
          
          </tr><?php  $a=0;
					while($rs=mysqli_fetch_assoc($result1)){

			if($a%2==0){ $b="#3f9";}
			else{$b="#CCFFFF";}
						?>
        <tr bgcolor="<?php echo $b ;?>">
          <td height="37" align="center" style="font-size: 12px">&nbsp;<?php echo $rs["as_code_new"];?></td>
	  <td align="center" style="font-size: 12px">&nbsp;<?php  echo $rs["as_code_old"];?></td>
          <td align="center" style="font-size: 12px">&nbsp;<?php  echo $rs["as_list"];?></td>
          <td align="left" style="font-size: 12px">&nbsp;<?php echo $rs["as_name"];?></td>
          <td align="center" style="font-size: 12px">&nbsp;<?php echo $rs["as_day"];?></td>
          <td align="center" style="font-size: 12px">&nbsp;<?php echo $rs["as_price"];?>&nbsp$</td>
          
          </tr> 
        <?php   $a++;
					 } 
 						mysqli_close($conn);
			?>
	<tr>
	
       <td align="center" colspan="6"><input type="button" name="button2" id="button2"  onclick="location.href='index.php'" value="กลับหน้าค้นหาทรัพย์สิน" />
                                     <input type="button" name="button2" id="button2"  onclick="location.href='report2.php'" value="กลับหน้าค้นหาสาขา" />
      </td>
	
        </tr>
    </table>
  </form>

</body>
</html>

