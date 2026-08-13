<?php 
include "connect_mtc.php";
$bod=$_GET["bod"];				
$cho1=$_GET["cho1"];
if($bod!=""){
						$sql="SELECT * FROM address where b_id = $bod" ;			
						$result=mysqli_query($conn,$sql);
						while($rs=mysqli_fetch_array($result)){
				$str.='<option value="'.$rs["a_id"].'">'.$rs["a_name"].'</option>';
							
							}
							echo $str;
						}




						if($cho1!=""){
							$sql="SELECT * FROM address where b_id = $cho1" ;			
						 	$result=mysqli_query($conn,$sql);
							while($rs=mysqli_fetch_array($result)){
				$str.='<option value="'.$rs["a_id"].'">'.$rs["a_name"].'</option>';
							
							}
							echo $str;
						}
											
											
mysqli_close($conn);
?>