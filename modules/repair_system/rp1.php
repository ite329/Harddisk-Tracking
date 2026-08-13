<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>จ่าหน้าพัสดุ A4 แนวนอน</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body {
      font-family: 'Sarabun', sans-serif;
      background-color: #f0f0f0;
    }

    .print-container {
      width: 100%;
      height: 100%;
      padding: 30px;
      background: white;
      max-width: 1000px;
      margin: 30px auto;
      border: 2px solid black;
      border-radius: 10px;
      box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }

    .header img {
      height: 60px;
    }

    .info-box {
      border: 1px dashed #000;
      padding: 20px;
      border-radius: 10px;
      margin-bottom: 20px;
    }

    .label {
      font-weight: bold;
    }

    .value {
      font-size: 1.5rem;
    }

    @media print {
      @page {
        size: A4 landscape;
        margin: 10mm;
      }

      .no-print {
        display: none !important;
      }

      body {
        background: white;
      }
    }
  </style>

    <link href="/harddisk_delivery_web/assets/css/hdd-sarabun-font.css?v=20260727" rel="stylesheet">
</head>
<body>

<div class="print-container">
  <div class="row mb-4 align-items-center">
    <div class="col-6">
      <img src="images/5.jpg" alt="โลโก้บริษัท" class="img-fluid">
    </div>
    <div class="col-6 text-end">
      <img src="images/KEX.png" alt="โลโก้ขนส่ง" class="img-fluid">
    </div>
  </div>

  <div class="info-box">
    <div class="mb-2"><span class="label">ชื่อผู้รับ:</span> <span class="value">นางสาว มาลี ดอกไม้</span></div>
    <div class="mb-2"><span class="label">ที่อยู่:</span> <span class="value">123 หมู่ 5 ต.ท่ามะกา อ.ท่ามะกา จ.กาญจนบุรี 71120</span></div>
    <div class="mb-2"><span class="label">เบอร์โทร:</span> <span class="value">081-234-5678</span></div>
    <div class="mb-2"><span class="label">สาขา:</span> <span class="value">สาขาท่ามะกา</span></div>
  </div>

  <div class="info-box">
    <div class="mb-2"><span class="label">ประเภทอุปกรณ์:</span> <span class="value">เครื่องพิมพ์ HP</span></div>
    <div class="mb-2"><span class="label">หมายเหตุ:</span> <span class="value">ห้ามตกกระแทก / Fragile</span></div>
  </div>

  <div class="text-end mt-4">
    <img src="images/barcode.png" height="60" alt="Barcode">
    <div class="text-muted">วันที่พิมพ์: <?= date('d/m/Y') ?></div>
  </div>
</div>

<div class="text-center mt-4 no-print">
  <button class="btn btn-primary btn-lg" onclick="window.print()">🖨️ พิมพ์ A4 แนวนอน</button>
  <a href="index.php" class="btn btn-secondary btn-lg">ย้อนกลับ</a>
</div>

</body>
</html>
