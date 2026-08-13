<?php   session_start();
$id =$_SESSION['id'];
$pass= $_SESSION['pass'];
if(isset($_SESSION["id"]) && isset($_SESSION["pass"])){
include "connect_mtc.php";
$id=$_GET["a_id"];
$b_id=$_GET["b_id"];
$re_diy_name=$_GET["re_diy_name"];
$name=$_POST["name"]; 
//echo  $re_diy_name ;    
       if($id!=""){
           $sql = " SELECT * FROM address where a_id= $id  ";
                              $q = mysqli_query( $conn, $sql );
                              $f = mysqli_fetch_assoc( $q );
       }
       if($b_id!="" && $re_diy_name!=""){
        $sql = " SELECT * FROM address where b_id=$b_id and a_name like '%$re_diy_name%'";
                                      $q = mysqli_query( $conn, $sql );
                                      $f = mysqli_fetch_assoc( $q );

       }

?>


<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />

        <link rel="stylesheet" media="all" type="text/css" href="jquery-ui.css" />
        <link rel="stylesheet" media="all" type="text/css" href="jquery-ui-timepicker-addon.css" />
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/2.2.0/jquery.min.js"></script>  
        <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" />  
        <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/js/bootstrap.min.js"></script> 

<script type="text/javascript">
function distributionChange() {
  var img ;
if(document.getElementById("test"))
{
      img = document.getElementById("test")
}else
{
      img = document.createElement("img");
      img.id = "test"
      img.setAttribute("width", "300");
      img.setAttribute("height", "200");
  document.getElementById("distribution").appendChild(img);
}

  switch (document.getElementById("distributions").value) {
    case '':
      img.src =  "images/0.png";
      break;
    case '1':
      img.src =  "images/HP430.png";
      break;
    case '2':
      img.src = "images/Acer.webp";
      break;
    case '3':
      img.src =  "images/Monitor.png";
      break;
    case '4':
      img.src = "images/Cam Watashi.jpg";
      break; 
    case '5':
      img.src = "images/HDDCCTV.jpg";
      break;
	  case '6':
      img.src = "images/Ram.jpg";
      break;
    case '7':
      img.src = "images/adapter.jfif";
      break;  
      case '8':
      img.src = "images/WSR0.webp";
      break;
      case '9':
      img.src = "images/DCPL5600DN_1.jpg";
      break;  
	  case '10':
      img.src = "images/TN3668P.jpg";
      break; 
	  case '11':
      img.src = "images/EPSON.jpg";
      break; 
	  case '12':
      img.src = "images/KEX.png";
      break;
       case '14':
      img.src = "images/3455.jpg";
      break;
      case '15':
      img.src = "images/3608.jpg";
      break;
  }

}
</script>

<title>ใบที่อยู่ส่งของ</title>
<style type="text/css">
body p {
	font-size: 24px;
}
body p {
	font-weight: bold;
}
</style>

<style type="text/css"> 
@media print 
{ 
#non-printable { display: none; } 
#printable { display: block; } 
} 
</style> 

    <link href="/harddisk_delivery_web/assets/css/hdd-sarabun-font.css?v=20260727" rel="stylesheet">
</head>

<body>
<form id="form1" name="form1" method="post" action="report1.php">
<div id="printable"> 
<table width="1112" height="617" border="0" align="center">
  <tr>
    <td width="575" height="266"><table width="539" height="226" border="0" align="center">
      <tr>
        <td width="529" height="220"><table>
          <tbody>
            <tr>
              <td height="115"><p>บริษัทเมืองไทยแคปปิตอล จำกัด(มหาชน)<br/>
                332/1 ถนนจรัญสนิทวงศ์ แขวงบางพลัด <br/>เขตบางพลัด กรุงเทพมหานคร 10700</p>
                <p>โทร 02-483-8888,061-271-3113</p></td>
            </tr>
          </tbody>
        </table></td>
      </tr>
    </table></td>
    <td width="521" valign="top" align="center" style="font-size: 36px; font-weight: bold; font-family: 'Comic Sans MS', cursive;">
	<br/>
		<img src="images/5.jpg"  width="100" height="100">
		<img src="images/KEX.png"  width="150" height="100">
	</td>
  </tr>
  <tr height="400">
    <td height="319"><table width="567" height="253" border="0">
      <tr>
        <td align="center" style="font-size: 36px; font-weight: bold;"><p> <span style="font-size: 36px"></span></p>
		<img src="images/Fragile.png"  width="230" height="120">
          <p>
            <br><?php if($re_diy_name==""){ ?>
		<label for="distributions">
		<select align="" style="font: size 28px; font-weight: bold;"  id='distributions' onChange='distributionChange()'>
                <option value=""> =>เลือกประเภทอุปกรณ์<= </option> 
                <option value="1">เครื่องปริ้นเตอร์ HP</option>
                <option value="9">เครื่องปริ้นเตอร์ Brother</option>
                <option value="2">คอมพิวเตอร์</option>
                <option value="3">จอคอมพิวเตอร์</option>
                <option value="4">กล้องวงจรปิด CCTV</option>
                <option value="8">เครื่องบันทึกกล้องวงจรปิด</option>
                <option value="5">HDD กล้อง</option>
                <option value="6">RAM </option>
                <option value="7">Adapter</option>
                <option value="10">ตลับหมึก Brother 5915</option>
                <option value="14">Drum 3455</option>
                <option value="15">Drum 3608</option>
				<option value="11">Projector</option>
              </select>		
		</label>
      <?php }else{ ?>
        <img src="images/HDDCCTV.jpg"  width="150" height="100">
         <?php }?>
		<br/>
		<p id="distribution"></p>
          </p></td>


      </tr>
    </table></td>
    <td valign="bottom"><table width="515" height="265" border="0">
      <tr>
        <td height="62"><p>บริษัทเมืองไทยแคปปิตอล จำกัด(มหาชน)</p></td>
      </tr>
      <tr>
        <td height="37">สาขา <?php echo $f["b_id"]?> &nbsp;&nbsp; <?php echo $f["a_name"]?>
        

              </td>
      </tr>
      <tr>
        <td height="103" style="font-size: 24px;font-weight: bold;">&nbsp;<?php echo $f["a_add"] ?></td>
      </tr>
      <tr>
        <td height="53" style="font-size: 24px;font-weight: bold;">โทร &nbsp;&nbsp;&nbsp;<?php echo $f["a_phon"]?></td>
         <tr> 
        <td height="53" style="font-size: 14px;">สถานที่ใกล้เคียง&nbsp;<?php echo $f["a_ne"]?></td>
        </tr>

      
       
  </table></td>
  </tr>
  <tr>
    <td height="24" colspan="2" align="center">&nbsp;</td>
  </tr>
</table>
</div>

<div id="non-printable"> 
<table width="450" border="0" align="center">
  <tr>
    <td height="50" align="center"><input type="submit" name="button" id="button" value="ค้นหา" />
      &nbsp;&nbsp;
      <input type="button" value="Print" onclick="window.print();" />
      &nbsp;&nbsp;
      <input type="button" name="button2" id="button2"  onclick="location.href='index.php'" value="HOME" />
	  &nbsp;&nbsp;
      <input type="button" name="button2" id="button2"  onclick="location.href='report2.php'" value="กลับหน้าที่อยู่ย่อย" />
      &nbsp;&nbsp;
      <input type="button" name="button2" id="button2"  onclick="location.href='show_diy_repair.php'" value="กลับหน้า HDD" />
      </a></td>
  </tr>
</table>
</div>
<p>&nbsp;</p>
</form>
</body>
</html>
<?php  
mysqli_close( $conn);

}
else{
	echo"<script> alert('loginก่อน'); window.location='login1.php';</script>";
exit();
}
?>