<?php
include "connect_mtc.php";
   $re_diy_id=$_GET["re_diy_id"];
   $id=$_GET["id"];
 //echo $de_co_id,'<br>',$id,$de_poin;
?><?php
 
           if($re_diy_id!=""){
                    $sql1="DELETE FROM `report_diy` WHERE re_diy_id=$re_diy_id "; 
                    mysqli_query($conn,$sql1)
 			           or die("1.ไม่สามารถบันทึกข้อมูลได้");
			           mysqli_close($conn);

                   }else{ ?>
                     <script language="javascript">
                     window.location='show_diy_repair.php';
                     </script>
           <?php
           }
    ?>
                     <script language="javascript">
                     window.location='show_diy_repair.php';
                     </script>
  