<style>
body {
    background-color: #f7f7f7;
    font-family: 'Tahoma', Geneva, sans-serif;
    font-size: 14px;
    color: #333;
    padding-top: 20px;
}
h2.title {
    text-align: center;
    margin-bottom: 30px;
    font-weight: bold;
    color: #2c3e50;
}
.table > thead > tr > th {
    background-color: #27ae60;
    color: #fff;
    text-align: center;
    font-weight: bold;
}
.table > tbody > tr:nth-child(even) {
    background-color: #e9f7ef;
}
.table > tbody > tr:nth-child(odd) {
    background-color: #d5f5e3;
}
.pagination {
    margin: 20px 0;
    text-align: center;
}
.pagination > li > a, .pagination > li > span {
    color: #27ae60;
}
.pagination > li.active > a, .pagination > li.active > span {
    background-color: #27ae60;
    border-color: #27ae60;
    color: #fff;
}
#menu-container {
    background-color: #fff;
    padding: 15px;
    border: 1px solid #ddd;
    height: 100%;
    box-shadow: 0 0 5px rgba(0,0,0,0.1);
}

 /* ตกแต่งกล่องเมนูซ้าย */
        .col-md-3 {
            background-color: #e9f7ef; /* สีเขียวอ่อน */
            padding: 15px;
            border-radius: 8px;
            box-shadow: inset 0 0 5px rgba(0,0,0,0.1);
            min-height: 500px;
        }

        /* เมนูเป็นรายการแนวตั้ง */
        .nav-pills > li > a {
            font-weight: 600;
            color: #2c3e50;
            padding: 10px 15px;
            border-radius: 6px;
            margin-bottom: 5px;
            background-color: #e9f7ef;
            border: 1px solid #b2d8b2;
        }
        .nav-pills > li > a:hover,
        .nav-pills > li.active > a {
            background-color: #28a745 !important;
            color: white !important;
            border-color: #1e7e34 !important;
        }
</style>

<div id="sidebar-menu">
  <table border="0" cellpadding="0" cellspacing="0">
     <!-- เมนูซ้าย -->
        <div class="col-md-3">
            <ul class="nav nav-pills nav-stacked" style="margin-top: 15px;">
              <h4>เมนูหลัก</h4>
                <li><a href="../requests/create.php">0. ระบบส่ง Harddisk</a></li>
                <li><a href="index.php">1. หน้าเช็คทรัพย์สิน</a></li>
                <li><a href="server.php">2. เครื่อง server</a></li>
                <li><a href="system_information.php">3. ข้อมูลระบบไอที</a></li>
                <li><a href="show_software.php">4.Software License</a></li>
                <li><a href="report2.php">5. ปริ้นที่อยุ่ส่งสาขา</a></li>
                <li><a href="show_NB.php">6. ข้อมูล License NB</a></li>
                <li><a href="show_com_re.php">7. Keyboard & Mouse</a></li>
                <li><a href="show_drum.php">8. เบิกDrum</a></li>
                <li><a href="show_diy_repair.php">9. ส่งอุปกรณ์ HDD</a></li>
                <li><a href="show_del_computer.php">10. ลบเครื่อง Joindomain</a></li>
                <li><a href="../serial_computer/show_sncom.php" target="_blank" rel="noopener">11. ข้อมูลคอมพิวเตอร์</a></li>
                <li><a href="../../public/logout.php">ออกจากระบบ</a></li>
            </ul>
        </div>
  </table>
</div>
