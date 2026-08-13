<?php session_start();
$id =$_SESSION['id'];
$pass= $_SESSION['pass'];
if(isset($_SESSION["id"]) && isset($_SESSION["pass"])){
include "connect_mtc.php";
    //if(!empty($_POST)){
// $output = '';
       $co_id=$_GET["co_id"];
       $l_id=$id ;
       $co_re_day= date("Y-m-d");
       //echo  $co_id,"<br>", $l_id,"<br>", $co_re_day ;

        $sql="INSERT INTO `computer_report`(`co_id`, `co_re_day`, `l_id`) VALUES ('$co_id','$co_re_day','$l_id')";
              mysqli_query($conn,$sql)
              or die("1.ไม่สามารถบันทึกข้อมูลได้");
              //mysqli_close($conn);

        $sql1="UPDATE `computer_repair` SET `co_poin`='1' WHERE co_id='$co_id'";
		    mysqli_query($conn,$sql1)
 			or die("2.ไม่สามารถบันทึกข้อมูลได้");
			mysqli_close($conn);	

       ?>
       <script language="javascript">
	alert   ('รับข้อมูลเรียบร้อยแล้ว');
	window.location='show_com_re.php';
	</script>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>ลงข้อมูล</title>

    <link href="/harddisk_delivery_web/assets/css/hdd-sarabun-font.css?v=20260727" rel="stylesheet">
</head>


<body>
</body>
</html>

<?php
}
else{
	echo"<script> alert('loginก่อน'); window.location='login1.php';</script>";
exit();
}

?>