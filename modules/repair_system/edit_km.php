<?php
include "connect_mtc.php";

if(!empty($_POST)){
    $km_poin2="1";
    $km_name=$_POST["km_name"];
    $km_sn=$_POST["km_sn"];
    $group_id=$_POST["group_id"];
    $km_l_id2=$_POST["l_id"];
    $km_day2=date("Y-m-d");
   
   // echo "<br>",$km_name,"<br>","<br>",$km_poin2,"<br>",$group_id,"<br>",$km_l_id2,"<br>",$km_day2,"<br>",$km_sn;
    
    $s="SELECT*FROM `keyboard_mouse_diy` Where km_sn ='$km_sn'";
    $re1=mysqli_query($conn,$s);
    $r=mysqli_fetch_array($re1);
    $d=$r["km_sn"];
   if($km_sn!="" ){
            if($d!=""){
        $sql1="UPDATE `keyboard_mouse_diy` SET `km_l_id2`='$km_l_id2',`group_id`='$group_id',`km_day2`='$km_day2',`km_poin2`='$km_poin2',`km_name`='$km_name' WHERE km_id=$r[km_id] ";
        mysqli_query($conn,$sql1)
         or die("1.ไม่สามารถบันทึกข้อมูลได้");

                        }else{ ?>
                        <script language="javascript">
                        alert   ('SN นี้ไม่มีในระบบ');
                       window.location='show_com_re.php';
                      </script>

    <?php
                            }
                     }else{?>
       <script language="javascript">
        alert   ('ใส่ SN มาด้วย ');
       window.location='show_com_re.php';
      </script>

    
    <?php
                             }
}

?>

<script language="javascript">
                        alert   ('ส่งข้อมูลเรียบร้อย');
                        window.location='show_com_re.php';
                        </script>
                        