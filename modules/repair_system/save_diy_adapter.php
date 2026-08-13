<?php
include "connect_mtc.php";
if(!empty($_POST)){
// $output = '';
 $re_diy_ad_b_id=$_POST["r_cho"];
 $re_diy_ad_num=$_POST["re_diy_ad_num"];
 $bry_id=$_POST["r_cho_ru"];
 $re_diy_ad_day=$_POST["re_diy_day"];
 $re_diy_ad_l_id=$_POST["l_id"];
 $re_diy_ad_id2=1;
 //echo $b_id,"<br>",$d_sn,"<br>",$bry_id,"<br>",$re_diy_day,"<br>",$re_l_id ,"<br>";
 //echo $re_diy_id;
//}
if($re_diy_ad_l_id!="" && $bry_id !=""&& $re_diy_ad_day!="") {
   $sql3="SELECT*FROM `address` Where a_id ='$bry_id'";
      $result3=mysqli_query($conn,$sql3);
      $rs3=mysqli_fetch_array($result3);
      $re_diy_ad_name=$rs3["a_name"];
  echo $re_diy_name;
      $sql1="SELECT*FROM `login` Where l_id ='$re_diy_ad_l_id'";
      $result1=mysqli_query($conn,$sql1);
      $total1=mysqli_num_rows($result1);
    
              if($total1=="1"){
             $sql="INSERT INTO `report_diy_adapter`( `re_diy_ad_b_id`, `re_diy_ad_name`, `re_diy_ad_day`, `re_diy_ad_l_id`, `re_diy_ad_id2`, `re_diy_ad_num`) VALUES ('$re_diy_ad_b_id','$re_diy_ad_name','$re_diy_ad_day','$re_diy_ad_l_id','$re_diy_ad_id2','$re_diy_ad_num')";
             mysqli_query($conn,$sql)
            or die("1.ไม่สามารถบันทึกข้อมูลรายงานได้");


            $sql3="SELECT*FROM `diy_adapter` WHERE diy_ad_id='$re_diy_ad_id2'";
            $result3=mysqli_query($conn,$sql3);
            $rs3=mysqli_fetch_array($result3);

            $num = $rs3["diy_ad_num"]-$re_diy_ad_num ;


            $sql4="UPDATE `diy_adapter` SET `diy_ad_num`='$num' WHERE diy_ad_id=$re_diy_ad_id2 ";
            mysqli_query($conn,$sql4)
            or die("2.ไม่สามารถบันทึกจำนวน adapter");

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
  alert   ('เพิ่มข้อมูลเรียบร้อยแล้ว');
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