<?php
include "connect_mtc.php";
if(!empty($_POST)){
// $output = '';
 $name_com_del=$_POST["name_com_del"];

 $name_com_new=$_POST["name_com_new"];
 $re_l_id=$_POST["l_id"];
 $de_poin="2";
 //echo $name_com_del,"<br>",$name_com_new,"<br>",$re_l_id,"<br>",$de_poin ,"<br>";
 //echo $re_diy_id;
//}

if($re_l_id!="" && $name_com_del !=""&& $name_com_new!="") {
    
      $sql1="SELECT*FROM `login` Where l_id ='$re_l_id'";
      $result1=mysqli_query($conn,$sql1);
      $total1=mysqli_num_rows($result1);
    
              if($total1=="1"){
             $sql="INSERT INTO `delete_computer`( `name_com_new`, `name_com_del`, `de_name_l_new`, `de_poin`) VALUES ('$name_com_new','$name_com_del','$re_l_id','$de_poin')";
             mysqli_query($conn,$sql)
            or die("1.ไม่สามารถบันทึกข้อมูลได้");
            mysqli_close($conn);	
  
            }else{
                      
           ?>
      <script language="javascript">
      alert   ('รหัสพนักงานนี้ไม่มีในระบบ');
      window.location='show_del_computer.php';
      </script>
        <?php
     }
  }else{
     ?>
  <script language="javascript">
  alert   ('กรุณาป้อนข้อมุลให้ครบด้วย');
  window.location='show_del_computer.php';
  </script>
  <?php
      }
  }
  ?>
  <script language="javascript">
  alert   ('เพิ่มข้อมูลเรียบร้อยแล้ว');
  window.location='show_del_computer.php';
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