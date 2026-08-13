<?php
include "connect_mtc.php";
if(!empty($_POST)){
    $re_diy_id=$_POST["re_diy_id"];
    $d_sn=$_POST["d_sn"];
    $re_l_id2=$_POST["id"];
    $re_diy_day1=date("Y-m-d");
   // echo $re_diy_id,"<br>",$d_sn,"<br>","<br>",$re_diy_day1;

           if($d_sn!=""){
            $sql2="SELECT*FROM `diy` Where d_sn ='$d_sn'";
            $result2=mysqli_query($conn,$sql2);
            $rs2=mysqli_fetch_array($result2);
            $d=$rs2["d_id"];
           
                if($rs2["d_id"]){    
                    
                    $sql1="UPDATE `report_diy` SET `d_sn`='$d_sn',`re_diy_day1`='$re_diy_day1',`re_l_id2`='$re_l_id2' WHERE re_diy_id=$re_diy_id ";
                    mysqli_query($conn,$sql1)
 			        or die("1.ไม่สามารถบันทึกข้อมูลได้");
			        mysqli_close($conn);
                   
                       }else{ ?>

                        <script language="javascript">
                        alert   ('SN_HDD นี้ไม่มีในระบบ');
                       window.location='show_diy_repair.php';
                      </script>

                      <?php
                         }

           }else{ ?>
                     <script language="javascript">
                     alert   ('กรอก SN_HDD ด้วย');
                     window.location='show_diy_repair.php';
                     </script>
           <?php
           }
}
    ?>
                     <script language="javascript">
                     window.location='show_diy_repair.php';
                     </script>
  