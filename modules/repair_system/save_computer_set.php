<?php
include "connect_mtc.php";
if(!empty($_POST)){
// $output = '';
 $l_id=$_POST["l_id"];
 $b_id=$_POST["b_id"];
 $re_co_br_pcname=$_POST["re_co_br_pcname"];
 $re_co_br_pcsn=$_POST["re_co_br_pcsn"];
 $group_pc_id=$_POST["group_pc_id"];
 $re_co_br_day=$_POST["re_co_br_day"];
 $mail="branch$b_id";
 //echo $b_id,"<br>",$re_co_br_pcname,"<br>",$re_co_br_pcsn,"<br>",$l_id,"<br>",$re_co_br_day ;
 echo $mail;
 if($l_id!="") {

    $sql3="SELECT*FROM `mail_branch` Where m_branch ='$mail'";
    $result3=mysqli_query($conn,$sql3);
    $rs3=mysqli_fetch_array($result3);
    $m=$rs3["m_b_id"];

    $sql1="SELECT*FROM `login` Where l_id ='$l_id'";
    $result1=mysqli_query($conn,$sql1);
    $total1=mysqli_num_rows($result1);
  
            if($total1=="1"){
          
          $sql="INSERT INTO `report_computer_branch`(`b_id`, `re_co_br_pcname`, `re_co_br_pcsn`, `re_co_br_day`,`l_id`,`m_b_id`,`group_pc_id`) VALUES ('$b_id','$re_co_br_pcname','$re_co_br_pcsn','$re_co_br_day','$l_id','$m','$group_pc_id')";
          mysqli_query($conn,$sql)
            or die("1.ไม่สามารถบันทึกข้อมูลได้");
          mysqli_close($conn);		
      }else{
                    
           ?>
           <script language="javascript">
           alert   ('รหัสพนักงานนี้ไม่มีในระบบ');
           window.location='show_computer_set.php';
           </script>
             <?php
          }
}else{
          ?>
<script language="javascript">
alert   ('กรุณาป้อนข้อมุลให้ครบด้วย');
window.location='show_computer_set.php';
</script>
<?php
}
}
?>
<script language="javascript">
alert   ('เพิ่มข้อมูลเรียบร้อยแล้ว');
window.location='show_computer_set.php';
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