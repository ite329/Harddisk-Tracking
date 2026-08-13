<?php
include "connect_mtc.php";

if(!empty($_POST)){
    $re_diy_id=$_POST["re_diy_id"];
    $re_diy_poin="1";
    $re_diy_day2=date("Y-m-d");
   // echo $re_diy_id,"<br>",$d_sn,"<br>","<br>",$re_diy_day1;
                    $s="SELECT*FROM `report_diy` Where re_diy_id ='$re_diy_id'";
                    $res2=mysqli_query($conn,$s);
                    $r=mysqli_fetch_array($res2);
                    $d=$r["d_sn"];

                 if($re_diy_id!="" && $d!=""){

                    $sql1="UPDATE `report_diy` SET `re_diy_poin`='$re_diy_poin',`re_diy_day2`='$re_diy_day2' WHERE re_diy_id=$re_diy_id ";
                    mysqli_query($conn,$sql1)
 			        or die("1.ไม่สามารถบันทึกข้อมูลได้");

                    $sql3="DELETE FROM `diy` WHERE d_sn='$d' ";
                     mysqli_query($conn,$sql3);
                    // or die("2.ไม่สามารถลบข้อมูล DIY ได้");
			         mysqli_close($conn);

                 }else{ ?>
                    <script language="javascript">
                        alert   ('ข้อมูลยังไม่ได้ส่ง');
                       window.location='show_diy_repair.php';
                      </script>

                <?php }
}

?>
                        <script language="javascript">
                        window.location='show_diy_repair.php';
                        </script>