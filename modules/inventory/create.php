<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_login();
$pageTitle = 'เพิ่ม Harddisk เข้าคลัง';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="card"><div class="card-body"><form method="post" action="save.php"><?php echo csrf_field(); ?><div class="row g-3"><div class="col-md-6"><label class="form-label">Serial HDD / Barcode</label><input class="form-control" name="hdd_serial" required autofocus></div><div class="col-md-3"><label class="form-label">Brand</label><input class="form-control" name="brand" placeholder="Western Digital"></div><div class="col-md-3"><label class="form-label">Model</label><input class="form-control" name="model" placeholder="Purple"></div><div class="col-md-3"><label class="form-label">Capacity</label><input class="form-control" name="capacity" placeholder="1TB"></div><div class="col-md-5"><label class="form-label">รับจาก</label><input class="form-control" name="received_from" placeholder="IT Stock / Supplier"></div><div class="col-md-4"><label class="form-label">วันที่รับเข้า</label><input type="date" class="form-control" name="received_date" value="<?php echo date('Y-m-d'); ?>"></div><div class="col-12"><label class="form-label">หมายเหตุ</label><textarea class="form-control" name="remark" rows="3"></textarea></div></div><div class="d-flex justify-content-between mt-4"><a href="index.php" class="btn btn-outline-secondary">กลับ</a><button class="btn btn-primary">บันทึก HDD</button></div></form></div></div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
