<?php
include "connect_mtc.php";
if(!empty($_POST)){
// $output = '';
 $id=$_POST["id"];
 $nb_id=$_POST["nb_id"];
 $nb_sn_nb=$_POST["nb_sn_nb"];
 $nb_name=$_POST["nb_name"];
 $nb_email=$_POST["nb_email"];
 $nb_pass_email=$_POST["nb_pass_email"];
 $nb_key_off=$_POST["nb_key_off"];
 $nb_cn_win=$_POST["nb_cn_win"];
 $group_id=$_POST["group_id"];
 

// echo $id,"<br>",$nb_id,"<br>", $nb_sn_nb,"<br>", $nb_name,"<br>", $nb_email,"<br>", $nb_pass_email,"<br>", $nb_key_off,"<br>", $nb_cn_win,"<br>",$nb_day,"<br>",$l_id,"<br>";


	  if($group_id!=''){       
            $sql="UPDATE `notebook` SET `nb_sn_nb`='$nb_sn_nb',`nb_name`='$nb_name',`nb_email`='$nb_email',`nb_pass_email`='$nb_pass_email',`nb_key_off`='$nb_key_off',`nb_cn_win`='$nb_cn_win',`group_id`='$group_id' WHERE nb_id='$id'";
		    mysqli_query($conn,$sql)
 			or die("1.ไม่สามารถบันทึกข้อมูลได้");
			mysqli_close($conn);	
	  }	if($group_id==''){
		$sql="UPDATE `notebook` SET `nb_sn_nb`='$nb_sn_nb',`nb_name`='$nb_name',`nb_email`='$nb_email',`nb_pass_email`='$nb_pass_email',`nb_key_off`='$nb_key_off' ,`nb_cn_win`='$nb_cn_win' WHERE nb_id='$id'";
		    mysqli_query($conn,$sql)
 			or die("1.ไม่สามารถบันทึกข้อมูลได้");
			mysqli_close($conn);

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
<title>แก้ไขข้อมูล</title>

    <link href="/harddisk_delivery_web/assets/css/hdd-sarabun-font.css?v=20260727" rel="stylesheet">
</head>


<body>
</body>
</html>