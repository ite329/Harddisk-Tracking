<?php  
session_start();

if (!isset($_SESSION["id"]) || !isset($_SESSION["pass"])) {
    echo "<script>alert('กรุณา login ก่อน'); window.location='login1.php';</script>";
    exit();
}

include "connect_mtc.php";

$nb_sn_nb = isset($_POST["nb_sn_nb"]) ? $_POST["nb_sn_nb"] : "";
$search_id = isset($_POST["id"]) ? $_POST["id"] : "";

if ($nb_sn_nb == "") {
    $sql = "SELECT * FROM notebook";
    $result = mysqli_query($conn, $sql);
} else {
    $nb_sn_nb_escaped = mysqli_real_escape_string($conn, $nb_sn_nb);
    $sql = "SELECT * FROM notebook WHERE nb_sn_nb='$nb_sn_nb_escaped'";
    $result = mysqli_query($conn, $sql);
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>โปรแกรมเช็คทรัพย์สิน</title>

<!-- Bootstrap CSS -->
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" />

<style>
body {
    background-color: #f0f9f4;
    font-family: 'Tahoma', Geneva, sans-serif;
    font-size: 15px;
    color: #2e4a3b;
    padding-top: 20px;
    margin: 0;
}

.container-main {
    max-width: 1100px;
    margin: auto;
    background: white;
    border-radius: 10px;
    box-shadow: 0 3px 15px rgba(0,0,0,0.1);
    padding: 30px;
}

.sidebar {
    background-color: #d8f3dc;
    min-height: 100vh;
    padding: 20px;
    border-radius: 10px;
}

.sidebar h4 {
    font-weight: 700;
    color: #1b4332;
    margin-bottom: 25px;
    text-align: center;
    letter-spacing: 1px;
}

.sidebar a {
    display: block;
    color: #2e4a3b;
    padding: 12px 18px;
    margin-bottom: 8px;
    font-weight: 600;
    border-radius: 6px;
    text-decoration: none;
    transition: background-color 0.3s ease, color 0.3s ease;
    border: 1px solid transparent;
}

.sidebar a:hover,
.sidebar a.active {
    background-color: #40916c;
    color: white;
    border-color: #2d6a4f;
    text-decoration: none;
}

.main-content {
    padding-left: 30px;
}

h1.title {
    text-align: center;
    font-weight: 700;
    margin-bottom: 35px;
    color: #1b4332;
}

.search-box {
    margin-bottom: 30px;
    text-align: center;
}

.search-box input[type="text"] {
    width: 300px;
    max-width: 90%;
    padding: 10px 15px;
    font-size: 16px;
    border: 2px solid #40916c;
    border-radius: 6px 0 0 6px;
    outline: none;
    color: #2e4a3b;
    transition: border-color 0.3s ease;
}

.search-box input[type="text"]:focus {
    border-color: #2d6a4f;
}

.search-box input[type="submit"] {
    padding: 11px 25px;
    font-size: 16px;
    border: 2px solid #40916c;
    background-color: #40916c;
    color: white;
    border-radius: 0 6px 6px 0;
    cursor: pointer;
    transition: background-color 0.3s ease;
}

.search-box input[type="submit"]:hover {
    background-color: #2d6a4f;
    border-color: #2d6a4f;
}

.asset-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
    font-size: 16px;
}

.asset-table td {
    padding: 14px 20px;
    border-bottom: 1px solid #d8f3dc;
}

.asset-table td:first-child {
    font-weight: 600;
    background-color: #d8f3dc;
    width: 200px;
    color: #2e4a3b;
}

.no-result {
    text-align: center;
    color: #d00000;
    font-weight: 700;
    font-size: 18px;
    margin-top: 40px;
}

@media (max-width: 767px) {
    .sidebar {
        min-height: auto;
        margin-bottom: 20px;
        border-radius: 10px;
    }
    .main-content {
        padding-left: 0;
    }
    .search-box input[type="text"], .search-box input[type="submit"] {
        width: 90%;
        border-radius: 6px !important;
        margin: 5px 0;
    }
}
</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/2.2.0/jquery.min.js"></script>  
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/js/bootstrap.min.js"></script> 

<script>
function check_key_number(event) {
    let key = event.keyCode || event.which;
    if (key !== 13 && (key < 48 || key > 57)) {
        event.preventDefault();
    }
}
</script>


    <link href="/harddisk_delivery_web/assets/css/hdd-sarabun-font.css?v=20260727" rel="stylesheet">
</head>

<body>

<div class="container-main">
  <div class="row">
    <div >
   <?php  include "menu.php"; ?>
    </div>

    <div class="col-md-9 main-content">
      <h1 class="title">โปรแกรมเช็คทรัพย์สิน</h1>

      <form id="form1" name="form1" method="post" action="index.php" class="search-box">
        <input type="text" name="id" id="id" placeholder="ใส่รหัสทรัพย์สินเก่าหรือใหม่" onkeypress="check_key_number(event)" autocomplete="off" />
        <input type="submit" name="Submit" id="button" value="ค้นหา" />
      </form>

      <div class="result-section">
        <?php
        if ($search_id != ""):
            $search_id_escaped = mysqli_real_escape_string($conn, $search_id);
            $sql = "SELECT * FROM asset WHERE as_code_new='$search_id_escaped' OR as_code_old='$search_id_escaped'";
            $q = mysqli_query($conn, $sql);

            if ($q && mysqli_num_rows($q) > 0):
                $f = mysqli_fetch_assoc($q);
                $b_id = (int)$f['a_id'];
                $sql2 = "SELECT * FROM address WHERE b_id=$b_id AND a_poin=1";
                $q2 = mysqli_query($conn, $sql2);
                $f2 = mysqli_fetch_assoc($q2);
        ?>
            <table class="asset-table">
              <tr>
                <td>รหัสสาขา</td>
                <td><?= htmlspecialchars($f['a_id']); ?> &nbsp;<?= htmlspecialchars($f2['a_name']); ?></td>
              </tr>
              <tr>
                <td>ชื่อสาขา</td>
                <td><a href="report.php?as_id=<?= htmlspecialchars($f['as_id']); ?>"><?= htmlspecialchars($f['as_name']); ?></a></td>
              </tr>
              <tr>
                <td>รหัสทรัพย์สินใหม่</td>
                <td><?= htmlspecialchars($f['as_code_new']); ?></td>
              </tr>
              <tr>
                <td>รหัสทรัพย์สินเก่า</td>
                <td><?= htmlspecialchars($f['as_code_old']); ?></td>
              </tr>
              <tr>
                <td>วันที่รับทรัพย์สินเข้า</td>
                <td><?= htmlspecialchars($f['as_day']); ?> &nbsp; ราคาคงเหลือ <?= number_format($f['as_price'], 2); ?> บาท</td>
              </tr>
              <tr>
                <td>รายการทรัพย์สิน</td>
                <td><?= htmlspecialchars($f['as_list']); ?></td>
              </tr>
              <?php
                $old_date = new DateTime($f['as_day']);
                $current_date = new DateTime();

                $diff = $old_date->diff($current_date);
                ?>
                <tr>
                <td>อายุทรัพย์สิน</td>
                <td><?php echo " " . $diff->y . " ปี " . $diff->m . " เดือน " . $diff->d . " วัน"; ?></td>
              </tr>
            </table>
        <?php
            else:
                echo "<p class='no-result'>ไม่พบข้อมูลทรัพย์สินที่ค้นหา</p>";
            endif;
        endif;
        ?>
      </div>
    </div>
  </div>
</div>

</body>
</html>
