<?php
session_start();

if (!isset($_SESSION["id"]) || !isset($_SESSION["pass"])) {
    echo "<script>alert('กรุณา login ก่อน'); window.location='login1.php';</script>";
    exit();
}

include "connect_mtc.php";

$id = $_POST["id"] ?? "";
$a_re = $_POST["a_re"] ?? "";

if ($id != "") {
    $safe_id = mysqli_real_escape_string($conn, $id);
    $sql1 = "SELECT * FROM address WHERE a_name LIKE '%$safe_id%' OR b_id LIKE '%$safe_id%'";
    $result1 = mysqli_query($conn, $sql1);
} elseif ($a_re != "") {
    $safe_a_re = (int)$a_re;
    $sql1 = "SELECT * FROM address WHERE a_re = $safe_a_re";
    $result1 = mysqli_query($conn, $sql1);
} else {
    $result1 = false;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8" />
    <title>โปรแกรมปริ้นที่อยู่สาขาย่อย</title>

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" />

    <!-- jQuery UI CSS -->
    <link rel="stylesheet" href="jquery-ui.css" />
    <link rel="stylesheet" href="jquery-ui-timepicker-addon.css" />

    <style>
        body {
            background-color: #f0f2f5;
            font-family: Tahoma, Geneva, sans-serif;
            font-size: 14px;
            color: #333;
            padding-top: 20px;
        }
        .container-main {
            background: #fff;
            padding: 20px 25px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        h2.header-title {
            color: #28a745;
            margin-bottom: 25px;
            font-weight: bold;
            text-align: center;
        }
        .search-panel {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .search-panel input[type="text"] {
            width: 120px;
            display: inline-block;
            margin-right: 10px;
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

        /* ตกแต่งกล่องเนื้อหาขวา */
        .col-md-9 {
            background-color: #ffffff; /* สีขาว */
            padding: 25px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .table {
            background-color: #fefefe;
        }
        table th {
            background-color: #007bff;  /* สีน้ำเงินสดใส */
            color: white !important;
            text-align: center;
            vertical-align: middle !important;
            border-bottom: 2px solid #0056b3;
        }
        table tbody tr:nth-child(even) {
            background-color: #f1f9ff;  /* ฟ้าสว่าง */
        }
        table tbody tr:nth-child(odd) {
            background-color: #ffffff; /* ขาว */
        }
        table tbody tr:hover {
            background-color: #cce5ff; /* ไฮไลต์เมื่อเลื่อนเมาส์ */
        }
        a.btn-print {
            margin-left: 10px;
        }
        a.link-report {
            color: #155724;
            font-weight: 600;
            text-decoration: none;
        }
        a.link-report:hover {
            text-decoration: underline;
        }
        td, th {
            vertical-align: middle !important;
        }
        .btn-warning {
            background-color: #ffc107;
            border-color: #ffc107;
            color: #212529;
            font-weight: 600;
        }
        .btn-warning:hover {
            background-color: #e0a800;
            border-color: #d39e00;
            color: #212529;
        }
    </style>

    <!-- jQuery & Bootstrap JS -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/2.2.0/jquery.min.js"></script>
    <script src="jquery-ui.min.js"></script>
    <script src="jquery-ui-timepicker-addon.js"></script>
    <script src="jquery-ui-sliderAccess.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/js/bootstrap.min.js"></script>

    <script>
    $(document).ready(function() {
        // Datepicker
        $("#dateInput").datepicker({
            dateFormat: 'yy-m-dd'
        });
    });

    // ฟังก์ชั่นเปิด popup ปริ้นที่อยู่ใหญ่
    function openPopup(code = '') {
    const width = 1100;
    const height = 900;

    const dualScreenLeft = window.screenLeft !== undefined ? window.screenLeft : screen.left;
    const dualScreenTop = window.screenTop !== undefined ? window.screenTop : screen.top;

    const screenWidth = window.innerWidth || document.documentElement.clientWidth || screen.width;
    const screenHeight = window.innerHeight || document.documentElement.clientHeight || screen.height;

    const left = ((screenWidth - width) / 2) + dualScreenLeft;
    const top = ((screenHeight - height) / 2) + dualScreenTop;

    const popupOptions = `scrollbars=yes,resizable=yes,width=${width},height=${height},top=${top},left=${left}`;
    window.open(`report1.php?code=${encodeURIComponent(code)}`, "popupWindow", popupOptions);
}
    </script>


    <link href="/harddisk_delivery_web/assets/css/hdd-sarabun-font.css?v=20260727" rel="stylesheet">
</head>
<body>

<div class="container container-main">

    <h2 class="header-title">ค้นหาที่อยู่ที่จะปริ้นส่ง</h2>

    <div class="row">
       <div >
   <?php  include "menu.php"; ?>
    </div>

        <!-- เนื้อหาฝั่งขวา -->
        <div class="col-md-9">
            <form method="post" action="report2.php" class="search-panel form-inline">
                <label for="id" class="control-label">สาขา :</label>
                <input type="text" name="id" id="id" class="form-control" value="<?php echo htmlspecialchars($id); ?>" placeholder="ระบุชื่อหรือรหัสสาขา" />
                <button type="submit" class="btn btn-success">ค้นหา</button>
                <button type="button" class="btn btn-warning" onclick="openPopup()">ปริ้นที่อยู่ส่งสาขาใหญ่</button>
            </form>

            <div class="table-responsive" style="margin-top:20px;">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>รหัสสาขา</th>
                            <th>สาขาใหญ่</th>
                            <th>ชื่อสาขา/กดค้นทรัพย์สิน</th>
                            <th>cost</th>
                            <th>เบอร์โทรสาขา</th>
                            <th>เขต</th>
                            <th>จังหวัด</th>
                            <th>พิมพ์ที่อยู่</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($result1):
                        $a = 0;
                        while ($f = mysqli_fetch_array($result1)):

                            $sql5 = "SELECT * FROM address WHERE b_id = " . (int)$f['b_id'];
                            $result5 = mysqli_query($conn, $sql5);
                            $f5 = mysqli_fetch_array($result5);

                            $rowClass = ($a % 2 == 0) ? '' : 'active';  // Bootstrap active row style
                    ?>
                        <tr class="<?php echo $rowClass; ?>">
                            <td class="text-center"><?php echo htmlspecialchars($f["b_id"]); ?></td>
                            <td class="text-center"><?php echo htmlspecialchars($f5["a_name"]); ?></td>
                            
                            <td>
                                <a href="report.php?a_id=<?php echo urlencode($f["a_name"]); ?>" class="link-report">
                                    <?php echo htmlspecialchars($f['a_name']); ?>
                                </a>
                            </td>
                            <td class="text-center"><?php echo htmlspecialchars($f5[""]); ?></td>
                            <td class="text-center"><?php echo htmlspecialchars($f["a_phon"]); ?></td>
                            <td class="text-center"><?php echo htmlspecialchars($f["a_co"]); ?></td>
                            <td class="text-center"><?php echo htmlspecialchars($f["a_add"]); ?></td>
                            <td class="text-center">
                                <a href="test.php?a_id=<?php echo urlencode($f["a_id"]); ?>">
                                    <img src="images/23.png" alt="พิมพ์ที่อยู่" width="30" height="30" />
                                </a>
                            </td>
                        </tr>
                    <?php
                        $a++;
                        endwhile;
                        mysqli_close($conn);
                    else: ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">ไม่มีข้อมูลที่ค้นหา</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

</body>
</html>
