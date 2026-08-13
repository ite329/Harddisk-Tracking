<?php
include "connect_mtc.php";
if(!empty($_POST)){
// $output = '';
 $nb_id=$_POST["nb_id"];
 $nb_sn_nb=$_POST["nb_sn_nb"];
 $nb_name=$_POST["nb_name"];
 $nb_email=$_POST["nb_email"];
 $nb_pass_email=$_POST["nb_pass_email"];
 $nb_key_off=$_POST["nb_key_off"];
 $nb_cn_win=$_POST["nb_cn_win"];
 $nb_day=$_POST["nb_day"];
 $l_id=$_POST["l_id"];
 $group_id=$_POST["group_id"];
 //echo  $nb_sn_nb,"<br>", $nb_name,"<br>", $nb_email,"<br>", $nb_pass_email,"<br>", $nb_key_off,"<br>", $nb_cn_win,"<br>",$nb_day,"<br>",$l_id,"<br>";

 if($l_id!="") {
	          $sql1="SELECT*FROM `login` Where l_id ='$l_id'";
	          $result1=mysqli_query($conn,$sql1);
              $total1=mysqli_num_rows($result1);
            
	  				if($total1=="1"){
					
					$sql="INSERT INTO `notebook`(`nb_sn_nb`, `nb_name`, `nb_email`, `nb_pass_email`, `nb_key_off`, `nb_cn_win`, `nb_day`, `l_id`, `group_id`) VALUES ('$nb_sn_nb','$nb_name','$nb_email','$nb_pass_email','$nb_key_off','$nb_cn_win','$nb_day','$l_id','$group_id')";
					mysqli_query($conn,$sql)
 			 		or die("1.ไม่สามารถบันทึกข้อมูลได้");
					mysqli_close($conn);		
		        }else{
				 			 
				     ?>
    			     <script language="javascript">
				     alert   ('รหัสพนักงานนี้ไม่มีในระบบ');
				     window.location='form_add_nb.php';
				     </script>
   				    <?php
				    }
	}else{
	                ?>
	<script language="javascript">
	alert   ('กรุณาป้อนข้อมุลให้ครบด้วย');
	window.location='form_add_nb.php';
	</script>
<?php
	}
}
	?>
	<script language="javascript">
	window.location='show_NB.php';
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