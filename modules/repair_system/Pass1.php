<?php  session_start();
$id =$_SESSION['id'];
$pass= $_SESSION['pass'];
if(isset($_SESSION["id"]) && isset($_SESSION["pass"])){
include "connect_mtc.php";
$nb_sn_nb=$_POST["nb_sn_nb"];
//echo $nb_sn_nb;
					if($nb_sn_nb==""){

            $query=mysqli_query($conn,"SELECT COUNT(nb_id) FROM `notebook`");
	          $row = mysqli_fetch_row($query);

	          $rows = $row[0];

	          $page_rows = 5;  //จำนวนข้อมูลที่ต้องการให้แสดงใน 1 หน้า  ตย. 5 record / หน้า 

	          $last = ceil($rows/$page_rows);

	            if($last < 1){
		            $last = 1;
	              }

	              $pagenum = 1;

	              if(isset($_GET['pn'])){
		            $pagenum = preg_replace('#[^0-9]#', '', $_GET['pn']);
	                  }

	              if ($pagenum < 1) {
		                $pagenum = 1;
	                  }
	                else if ($pagenum > $last) {
		                       $pagenum = $last;
	                        }

	                    $limit = 'LIMIT ' .($pagenum - 1) * $page_rows .',' .$page_rows;

	                    $nquery=mysqli_query($conn,"SELECT * from  notebook $limit");

	                    $paginationCtrls = '';

	                    if($last != 1){

	                    if ($pagenum > 1) {
                      $previous = $pagenum - 1;
		                  $paginationCtrls .= '<a href="'.$_SERVER['PHP_SELF'].'?pn='.$previous.'" class="btn btn-info">Previous</a> &nbsp; &nbsp; ';

		                  for($i = $pagenum-4; $i < $pagenum; $i++){
			                if($i > 0){
		                  $paginationCtrls .= '<a href="'.$_SERVER['PHP_SELF'].'?pn='.$i.'" class="btn btn-primary">'.$i.'</a> &nbsp; ';
		                  	}
                    	}
                  }

	                  $paginationCtrls .= ''.$pagenum.' &nbsp; ';

	              for($i = $pagenum+1; $i <= $last; $i++){
		                $paginationCtrls .= '<a href="'.$_SERVER['PHP_SELF'].'?pn='.$i.'" class="btn btn-primary">'.$i.'</a> &nbsp; ';
		                if($i >= $pagenum+4){
		                  	break;
		                  }
	                  }

                if ($pagenum != $last) {
                    $next = $pagenum + 1;
                    $paginationCtrls .= ' &nbsp; &nbsp; <a href="'.$_SERVER['PHP_SELF'].'?pn='.$next.'" class="btn btn-info">Next</a> ';
                    }
	                  }
						}
					if($nb_sn_nb!=""){ 	
                                  $sql="SELECT * FROM `notebook` WHERE nb_sn_nb='$nb_sn_nb' " ;			
										              $result=mysqli_query($conn,$sql);
										
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

<script type="text/javascript" src="jquery-ui.min.js"></script>
<script type="text/javascript" src="jquery-ui-timepicker-addon.js"></script>
<script type="text/javascript" src="jquery-ui-sliderAccess.js"></script>
<script type="text/javascript" src="../jquery.js"></script>
<script type="text/javascript">
$(document).ready(function(){
     $TtoolTipAjax();
});
$TtoolTipAjax=function(){//ทีทูลทิป ฟังก์ชั่น
$('.lnk').hover(function(e){ //Mouse Hover แอทริบิวต์ คลาส ชื่อ lnk
$('body').append('<div class="showTooltip"> </div>');
var showTooltip=$('.showTooltip');
    $.ajax({//เรียกใช้ ajax ของ jQuery
        url:$(this).attr('turl')+'&'+new Date().getTime(),
        beforeSend :function(){//ก่อนส่งค่า 
             showTooltip.html('<img src="wait.gif"/>'); //แสดงตัว loading 
          },
        success:function(data){//ส่งค่าเสร็จสมบูรณ์ พร้อมกับผลลัพธุ์ถูกส่งกลับมา(data)
            showTooltip.html(data);
       }
    });
var mousex = e.pageX+20 ; 
var mousey = e.pageY-200;  
var tooltipWidth = showTooltip.width(); 
var tooltipHeight = showTooltip.height(); 
var toolVisX = $(window).width() - (mousex + tooltipWidth); 
var toolVisY = ($(window).height()+$(window).scrollTop())-(mousey+tooltipHeight); 
if ( toolVisX < 10 ) {  mousex = e.pageX - tooltipWidth - 40;  }
if ( toolVisY < 10 ) {   mousey = e.pageY - tooltipHeight - 10;  }
showTooltip.css({ top: mousey, left: mousex,display:'none'});
showTooltip.slideDown('slow');
},function(){ //Mouse Out
       $('.showTooltip').remove();//Remove Tooltip
})
}
</script>

<script>
$(function(){//การเลือกสาขา
	$("#group_id").change(function(){
		var pid=$(this).val();
		//alert(pid);
		$.get("data.php",{bod:pid},function(data){
			//alert(data);
			$("#bry_id").children().remove().end();
			$("#bry_id").children().end().append(data);
			$("#bry_id").removeAttr('disabled');
			});		
		});	
	});		
</script>
<script>
$(function(){//รุ่นอุปกร
	$("#r_cho").change(function(){
		var cho=$(this).val();
		//alert(pid);
		$.get("data.php",{cho1:cho},function(data){
			//alert(data);
			$("#r_cho_ru").children().remove().end();
			$("#r_cho_ru").children().end().append(data);
			$("#r_cho_ru").removeAttr('disabled');
			});		
		});	
	});		
</script>
<script type="text/javascript">
$(function(){//วันที่ส่ง
	$("#dateInput").datepicker({
		dateFormat: 'yy-m-dd'
	});
});

</script>
<title>ข้อมูล License Windows + office</title>
<style type="text/css">
a:link {
	text-decoration: none;
}
a:visited {
	text-decoration: none;
}
a:hover {
	text-decoration: none;
}
a:active {
	text-decoration: none;
}
body {
	background-color: #D6D6D6;
}
</style>
</script>

<style type="text/css">
/*ปรับสีสันของ Tooltip ได้จากคำสั่ง CSS ตรงนี้*/
body{
	font-size: 12px;
	font-family: Tahoma, Geneva, sans-serif;
	color: #E1E1E1;
	background-color: #CCCCCC;
}
.showTooltip{
float:left;
padding:10px;
background:#F3F3F3;
border:2px solid #CFCFCF;
-moz-border-radius: 4px;  
-webkit-border-radius: 4px;  
border-radius: 4px;
color:#333;
position:absolute;
}
a{
margin:5px;
color:#06C;
text-decoration:none;
}
body,td,th {
	color: #000000;
}
</style>
</style>
<script language="JavaScript" type="text/JavaScript">
function check_key_number() {
use_key=event.keyCode
if (use_key != 13 && (use_key < 48) || (use_key > 57)) {
event.returnValue = false;
}
}
</script>

<body>
<form id="form1" name="form1" method="post" action="show_NB.php">
<div id="employee_table">
  <table width="887" height="243" border="1" align="center" cellpadding="0" cellspacing="0" dir="ltr">
    <tr>
      <td height="58" colspan="2" bgcolor="#FFFFFF" style="text-align: center; font-size: 18px; font-weight: bold;">รายการ License Windows and Microsoft Office ประจำเครื่อง</td>
    </tr>
    <tr>
      <td width="201" height="183" valign="top" bgcolor="#FFFFFF"><table width="201" height="181" border="0" cellpadding="0" cellspacing="0">
        <tr>
          <td align="center" style="font-weight: bold">MENU</td>
        </tr>
        <tr>
          <td><span style="text-align: center; font-size: 16px;"><a href="index.php">&nbsp;1.เช็คทรัพย์สิน</a></span></td>
        </tr>
        <tr>
          <td style="font-size: 16px"><a href="wcs.php">&nbsp;2.อุปกรณ์ส่งซ่อม WCS</a></td>
        </tr>
        <tr>
          <td style="font-size: 16px"><a href="report1.php">&nbsp;3.ปริ้นที่อยู่ส่งสาขาใหญ่</a></td>
        </tr>
        <tr>
          <td style="font-size: 16px"><a href="report2.php">&nbsp;4.ปริ้นที่อยู่ส่งสาขาย่อย</a></td>
        </tr>
        <tr>
          <td style="font-size: 16px"><a href="show_NB.php">&nbsp;5.ข้อมูล License</a></td>
        </tr>
        <tr>
          <td style="font-size: 16px"><a href="show_com_re.php">&nbsp;6.กดรับคอมพิวเตอร์</a></td>
        </tr>
        <tr>
        <td style="font-size: 16px"><a href="logout.php">&nbsp;ออกจากระบบ</a></td>
        </tr>
        <tr>
          <td height="23" style="font-size: 14px"></td>
        </tr>
      </table></td>
      <td width="680" valign="top" bgcolor="#FFFFFF"><table width="676" border="1" align="center" cellpadding="0" cellspacing="0">
        <tr>
          <td height="34" align="right"><div align="right">
          
          <button type="button" name="add" id="add" data-toggle="modal" data-target="#add_data_Modal" class="btn btn-warning">เพิ่มข้อมูล License</button>
 
           
  </div></td>
          </tr>
        <tr>
          <td height="26" align="center"><input type="text" name="nb_sn_nb" id="nb_sn_nb" />
            <span style="font-size: 12px; color: #FF0000;">Serial Number เครื่อง&nbsp;&nbsp;
              <input type="submit" name="Submit" id="button" value="ค้นหา"  />
            </span></td>
          </tr>
      </table>
        <table width="676" border="1" cellspacing="0" cellpadding="0" align="center">
          <tr>
          <td width="40" height="35" align="center" bgcolor="#FFCC66" style="font-size: 14px;">ลำดับ</td>
          <td width="135" align="center" bgcolor="#FFCC66" style="font-size: 14px">ฝ่าย</td>
          <td width="150" align="center" bgcolor="#FFCC66" style="font-size: 14px">ผู้ใช้งานตัวเครื่อง</td>
          <td width="153" align="center" bgcolor="#FFCC66" style="font-size: 14px">Serial Number</td>
          <td width="66" align="center" bgcolor="#FFCC66" style="font-size: 14px">วันที่บันทึกข้อมูล</td>
          <td width="69" align="center" bgcolor="#FFCC66" style="font-size: 14px">รายละเอียด</td>
          <td width="50" align="center" bgcolor="#FFCC66" style="font-size: 14px">แก้ไข</td>
          </tr><?php $a=0; $i=1;
					
					while($crow = mysqli_fetch_array($nquery)){

						$sql1="SELECT * FROM `group_ps_center`  where group_id=$crow[group_id] " ;			
						$result1=mysqli_query($conn,$sql1);
						$rs1=mysqli_fetch_array($result1);

            $sql2="SELECT * FROM `login`  where l_id=$crow[l_id] " ;			
						$result2=mysqli_query($conn,$sql2);
						$rs2=mysqli_fetch_array($result2);

						if($a%2==0){ $b="#3f9";}
						else{$b="#CCFFFF";}
						?>
        <tr bgcolor="<?php echo $b ;?>" <?=$i?>>
          <td height="37" align="center" style="font-size: 12px">&nbsp;<?php echo $crow["nb_id"];?></td>
          <td align="center" style="font-size: 12px">&nbsp;<?php  echo $rs1["group_name"]; ?></td>
          <td align="center" style="font-size: 12px">&nbsp;<?php echo $crow["nb_name"];?></td>
          <td align="center" style="font-size: 12px">&nbsp;<?php echo $crow["nb_sn_nb"];?></td>
          <td align="center" style="font-size: 12px">&nbsp;<?php echo $crow["nb_day"];?></td>
          <td align="center" style="font-size: 12px">
          <span class="font-bold col-red font-17" data-toggle="tooltip" data-placement="right" title="ดูรายละเอียดเพิ่มเติม" style="cursor: pointer;">
          <img src="images/addressbook.ico" width="30" height="30"   data-toggle="modal" data-target="#detail<?=$i?>"/> 
          </span>
          </td>

          <td align="center" style="font-size: 12px"><?php echo "<a href=\"form_edit_nb.php?nb_id=$rs[nb_id]\">"?><img src="images/edit.ico" width="30" height="30" /><?php echo "</a>"; ?></td>
         <?php /*<button type="button" name="age" id="age" data-toggle="modal" data-target="#add_data_Modal" class="btn btn-warning">เพิ่มเติม</button> */ ?>
           </td>
           
          </tr> 
              <!-- Modal detail -->
      <div class="modal fade" id="detail<?=$i?>" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
                                                <div class="modal-dialog modal-lg" role="document">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                                                            <h4 class="modal-title" id="largeModalLabel">รายละเอียด</h4>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="body" style="padding: unset;">
                                                                <div class="panel panel-default panel-post" style="background-color: #fffdef;">
                                                                    <div class="panel-body">
                                                                        <div class="post">
                                                                            <div class="post-heading">
                                                                                <h4 class="media-heading col-teal">
                                                                                    <p class="col-red">
                                                                                        <img src="images/addressbook.ico" class="m-r-10 m-b-10" style="width: 50px;">
                                                                                        &nbsp;&nbsp;&nbsp;"<?=$rs['nb_sn_nb']?>"</p>
                                                                                </h4>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                    <div class="panel-body">
                                                                        <div class="post">
                                                                            <div class="post-heading">
                                                                                <div>
                                                                                    <p><b>วันที่บันทึกข้อมูล</b></p>
                                                                                    <p class="p-b-10"><?=$crow['nb_day'] ?></p>
                                                                                    <p><b>ผู้บันทึกข้อมูล</b></p>
                                                                                    <p class="p-b-10"><?=$rs2['l_name']?></p>
                                                                                    <p><b>ชื่อผู้ใช้งานตัวเครื่อง</b></p>
                                                                                    <p class="p-b-10"><?=$crow['nb_name']?></p>
																					<p><b>Key Microsoft Office</b></p>
                                                                                    <p class="p-b-10"><?=$crow['nb_key_off']?></p>
                                                                                    <p><b>ใบ PO</b></p>
                                                                                    <p class="p-b-10"><?=$crow['nb_cn_win']?></p>
                                                                                    <p><b>E-mail Microsoft Office</b></p>
                                                                                    <p class="p-b-10"><?=$crow['nb_email']?></p>
                                                                                    <p><b>รหัส E-mail Microsoft Office</b></p>
                                                                                    <p class="p-b-10"><?=$crow['nb_pass_email']?></p>
                                                                                    <p><b>Key Microsoft Office</b></p>
                                                                                    <p class="p-b-10"><?=$crow['nb_key_off']?></p>
                                                                                </div>
                                                                                
                                                                            </div>
                                                                        </div>
                                                                    </div>                                                                 
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                           
                                            <script type="text/javascript">
                                                $(document).ready(function(){
                                                    // $("#view<?=$i?>").click();
                                                });
                                            </script>                                               
          
          <?php  $a++;	$i++; } 	?>
         
          
    </table>
    <div id="pagination_controls"><?php echo $paginationCtrls; ?></div>
  </td>
    
    </tr>
  </table>
  
  </form>

</body>
</html>

<!--เพิ่มข้อมูล -->
   <div id="add_data_Modal" class="modal fade">
      <div class="modal-dialog">
         <div class="modal-content">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal">&times;</button>
              <h4 class="modal-title">เพิ่มข้อมูล License Windows and Microsoft Office</h4>
          </div>
                <div class="modal-body">
                   <form id="insert_form" action="save_nb.php" class="form-horizontal" method="post" enctype="multipart/form-data">
                      <label>รหัสพนักงานผู้รับเครื่องเข้าระบบ</label>
                      <input name="l_id" type="text" id="l_id" onkeypress="check_key_number();" class="form-control" />
                      <br />
                      <label>SN Notebook</label>
                      <input name="nb_sn_nb" type="text" id="nb_sn_nb"   class="form-control"/>
     			            <br />
                      <label>ชื่อผู้ใช้งานตัวเครื่อง</label> 
                      <input name="nb_name" type="text" id="nb_name"   class="form-control"/>  
                      <br />
                      <select name="group_id" id="group_id"  class="form-control">
                      <option value="">==>กรุณาเลือกฝ่าย<==</option>
                      <?php
             	               $sql11=" SELECT * FROM `group_ps_center` " ;
				                      $q11=mysqli_query($conn,$sql11);
			                        while ($f11=mysqli_fetch_array($q11)){
			                        ?>
                      <option value="<?php echo $f11["group_id"]?>"><?php echo $f11["group_name"]?></option>
                           <?php
                              } 
 						 
			                      ?>
                     </select> 
                   
                      <br />
                      <label for="bry_id">E-mail Microsoft Office</label>
                      <input name="nb_email" type="text" id="nb_email"   class="form-control"/>  
                      <br />
                      <label for="bry_id">รหัส E-mail Microsoft Office</label>
                      <input name="nb_pass_email" type="text" id="nb_pass_email"   class="form-control"/>  
                      <br />
                      <label>Key Microsoft Office</label> 
                      <input name="nb_key_off" type="text" id="nb_key_off" class="form-control" />
                      <br/>
                      <label>ใบ PO</label>  
                      <input name="nb_cn_win" type="text" id="nb_cn_win"  class="form-control" />
                      <br/>
                      <label>วันที่บันทึกข้อมูล</label>
                      <input name="nb_day" type="text" id="dateInput" value="0000-00-00"  class="form-control"/>
                      <br />
                            <input type="submit" name="insert" id="insert" value="Insert" class="btn btn-success" />
                      </form>
                    </div>

              <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
              </div>
        </div>
        </div>
    </div>

<div id="dataModal" class="modal fade">
 <div class="modal-dialog">
  <div class="modal-content">
   <div class="modal-header">
    <button type="button" class="close" data-dismiss="modal">&times;</button>
    
    <h4 class="modal-title">Employee Details</h4>
   </div>
   <div class="modal-body" id="employee_detail">
    
   </div>
   <div class="modal-footer">
    <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
   </div>
  </div>
 </div>
</div>

      

<script>  
$(document).ready(function(){
 $('#insert_form').on("submit", function(event){  
  event.preventDefault();  
  if($('#name').val() == "")  
  {  
   alert("Name is required");  
  }  
  else if($('#address').val() == '')  
  {  
   alert("Address is required");  
  }  
  else if($('#designation').val() == '')
  {  
   alert("Designation is required");  
  }
   
  else  
  {  
   $.ajax({  
    url:"save_nb.php",  
    method:"POST",  
    data:$('#insert_form').serialize(),  
    beforeSend:function(){  
     $('#insert').val("Inserting");  
    },  
    success:function(data){  
     $('#insert_form')[0].reset();  
     $('#add_data_Modal').modal('hide');  
     $('#employee_table').html(data);  
    }  
   });  
  }  
 });




 $(document).on('click', '.view_data', function(){
  //$('#dataModal').modal();
  var employee_id = $(this).attr("id");
  $.ajax({
   url:"test3.php",
   method:"POST",
   data:{employee_id:employee_id},
   success:function(data){
    $('#employee_detail').html(data);
    $('#dataModal').modal('show');
   }
  });
 });
});  
 </script>

<?php
}
else{
	echo"<script> alert('loginก่อน'); window.location='login1.php';</script>";
exit();
}
?>