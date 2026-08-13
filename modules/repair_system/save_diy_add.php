<?php
include "connect_mtc.php";
if(!empty($_POST)){
    
    $d_sn=$_POST["sn"];
    $d_day=date("Y-m-d");
  // echo $d_sn,"<br>","<br>",$d_day;

           if($d_sn!=""){
            $sql2="SELECT*FROM `diy` Where d_sn ='$d_sn'";
            $result2=mysqli_query($conn,$sql2);
            $num = mysqli_num_rows( $result2 );
                if($num==0){
                    
                    $sql1="INSERT INTO `diy` ( `d_sn`, `d_day`) VALUES ('$d_sn','$d_day') ";
                    mysqli_query($conn,$sql1)
 			        or die("1.ไม่สามารถบันทึกข้อมูลได้");
			         mysqli_close($conn);

                       }else{ ?>
                         <script language="javascript">
                         alert   ('SN_HDD นี้มีในระบบ');
                         window.location='frm_diy_add.php';
                         </script>

                    <?php } 
                     
           }else{?>
                 <script language="javascript">
                 alert   ('กรอกข้อมูลด้วย');
                 window.location='frm_diy_add.php';
                 </script>

          <?php }
}

    ?>
                 <script language="javascript">
                 window.location='frm_diy_add.php';
                 </script>