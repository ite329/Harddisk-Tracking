<?php
include "connect_mtc.php";
if(!empty($_POST)){
// $output = '';
 $km_sn=$_POST["b_id"];
 $km_day1=date("Y-m-d");
 $km_l_id=$_POST["l_id"];
 $km_poin=$_POST["km_poin"];
 $km_poin2="0";
 //echo $b_id,"<br>",$d_sn,"<br>",$bry_id,"<br>",$re_diy_day,"<br>",$re_l_id ,"<br>";
 //echo $re_diy_id;
//}
if($km_sn!="" && $km_l_id !=""&& $km_day1!="") {
      $sql1="SELECT*FROM `login` Where l_id ='$km_l_id'";
      $result1=mysqli_query($conn,$sql1);
      $total1=mysqli_num_rows($result1);
    
              if($total1=="1"){
             $sql="INSERT INTO `keyboard_mouse_diy`( `km_sn`, `km_l_id`, `km_day1`, `km_poin`, `km_poin2`) VALUES ('$km_sn','$km_l_id','$km_day1','$km_poin','$km_poin2')";
             mysqli_query($conn,$sql)
            or die("1.ไม่สามารถบันทึกข้อมูลได้");
            mysqli_close($conn);	
            
            }else{
                      
           ?>
      <script language="javascript">
      alert   ('รหัสพนักงานนี้ไม่มีในระบบ');
      window.location='show_com_re.php';
      </script>
        <?php
     }
  }else{
     ?>
  <script language="javascript">
  alert   ('กรุณาป้อนข้อมุลให้ครบด้วย');
  window.location='show_com_re.php';
  </script>
  <?php
      }
  }
  ?>
  <script language="javascript">
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