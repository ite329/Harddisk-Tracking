<?php
include "connect_mtc.php";
if(!empty($_POST)){
// $output = '';
 $b_id=$_POST["b_id"];

 $bry_id=$_POST["bry_id"];
 $re_diy_day=$_POST["re_diy_day"];
 $re_l_id=$_POST["l_id"];
 $re_diy_poin="0";
 //echo $b_id,"<br>",$d_sn,"<br>",$bry_id,"<br>",$re_diy_day,"<br>",$re_l_id ,"<br>";
 //echo $re_diy_id;
//}
if($re_l_id!="" && $bry_id !=""&& $re_diy_day!="") {
   $sql3="SELECT*FROM `address` Where a_id ='$bry_id'";
      $result3=mysqli_query($conn,$sql3);
      $rs3=mysqli_fetch_array($result3);
      $re_diy_name=$rs3["a_name"];
  echo $re_diy_name;
      $sql1="SELECT*FROM `login` Where l_id ='$re_l_id'";
      $result1=mysqli_query($conn,$sql1);
      $total1=mysqli_num_rows($result1);
    
              if($total1=="1"){
             $sql="INSERT INTO `report_diy`( `b_id`, `re_diy_name`, `re_diy_day`, `re_l_id`, `re_diy_poin`) VALUES ('$b_id','$re_diy_name','$re_diy_day','$re_l_id','$re_diy_poin')";
             mysqli_query($conn,$sql)
            or die("1.ไม่สามารถบันทึกข้อมูลได้");
            mysqli_close($conn);	
  
            }else{
                      
           ?>
      <script language="javascript">
      alert   ('รหัสพนักงานนี้ไม่มีในระบบ');
      window.location='show_diy_repair.php';
      </script>
        <?php
     }
  }else{
     ?>
  <script language="javascript">
  alert   ('กรุณาป้อนข้อมุลให้ครบด้วย');
  window.location='show_diy_repair.php';
  </script>
  <?php
      }
  }
  ?>
  <script language="javascript">
  window.location='show_diy_repair.php';
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