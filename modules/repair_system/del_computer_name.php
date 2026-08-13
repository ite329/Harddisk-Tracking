<?php
include "connect_mtc.php";
   $de_co_id=$_GET["de_co_id"];
   $id=$_GET["id"];
    $de_poin= "1";
 //echo $de_co_id,'<br>',$id,$de_poin;
?><?php
   if($id==839 || $id==9404 || $id==16470 || $id==17059){
           if($de_co_id!=""){
                    $sql1="UPDATE `delete_computer` SET `de_name_l_del`=$id,`de_poin`=$de_poin WHERE de_co_id=$de_co_id "; 
                    mysqli_query($conn,$sql1)
 			           or die("1.ไม่สามารถบันทึกข้อมูลได้");
			           mysqli_close($conn);

                   }else{ ?>
                     <script language="javascript">
                     alert   ('ข้อมูลผิด');
                     window.location='show_del_computer.php';
                     </script>
           <?php
           }
         }else{ 
            
            ?>
         
                   <script language="javascript">
                     alert   ('คุณไม่มีสิทธิลบข้อมูลนี้ได้');
                     window.location='show_del_computer.php';
                     </script>
         
         
         <?php


         }




    ?>
                     <script language="javascript">
                     window.location='show_del_computer.php';
                     </script>
  