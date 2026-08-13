<?php  
session_start();
require_once "connect_mtc.php"; // Include connection at the top

// Check authentication
if(!isset($_SESSION["id"]) || !isset($_SESSION["pass"])) {
    header("Location: login1.php");
    exit();
}

// Sanitize inputs
$id = isset($_POST['id']) ? mysqli_real_escape_string($conn, $_POST['id']) : '';
$nb_sn_nb = isset($_POST['nb_sn_nb']) ? mysqli_real_escape_string($conn, $_POST['nb_sn_nb']) : '';
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบจัดการทรัพย์สิน</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Sarabun', sans-serif;
        }
        .card {
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #3498db;
            color: white;
            border-radius: 10px 10px 0 0 !important;
        }
        .sidebar {
            background-color: #2c3e50;
            min-height: 100vh;
            color: white;
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            margin-bottom: 5px;
        }
        .sidebar .nav-link:hover {
            color: white;
            background-color: rgba(255, 255, 255, 0.1);
        }
        .sidebar .nav-link.active {
            color: white;
            background-color: #3498db;
        }
        .asset-detail {
            font-size: 1.1rem;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        .asset-label {
            font-weight: 600;
            color: #3498db;
        }
        .search-box {
            max-width: 600px;
            margin: 0 auto 30px;
        }
    </style>

    <link href="/harddisk_delivery_web/assets/css/hdd-sarabun-font.css?v=20260727" rel="stylesheet">
</head>

<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 d-md-block sidebar collapse bg-dark">
                <div class="position-sticky pt-3">
                    <h4 class="text-center mb-4">
                        <i class="fas fa-cubes"></i> ระบบทรัพย์สิน
                    </h4>
                    <?php include "menu.php"; ?>
                </div>
            </div>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-search"></i> ค้นหาทรัพย์สิน
                    </h1>
                </div>

                <!-- Search Form -->
                <div class="card search-box">
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <div class="col-md-8">
                                <input type="text" name="id" class="form-control form-control-lg" 
                                       placeholder="ระบุรหัสทรัพย์สิน (เก่าหรือใหม่)" value="<?= htmlspecialchars($id) ?>">
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-primary btn-lg w-100">
                                    <i class="fas fa-search"></i> ค้นหา
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if($id): ?>
                <!-- Asset Details -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle"></i> รายละเอียดทรัพย์สิน</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $sql = "SELECT a.*, ad.a_name 
                                FROM asset a
                                LEFT JOIN address ad ON a.a_id = ad.b_id AND ad.a_poin = 1
                                WHERE a.as_code_new = '$id' OR a.as_code_old = '$id'";
                        $result = mysqli_query($conn, $sql);
                        
                        if(mysqli_num_rows($result) > 0) {
                            $asset = mysqli_fetch_assoc($result);
                        ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="asset-detail">
                                        <span class="asset-label">รหัสสาขา:</span>
                                        <?= htmlspecialchars($asset['a_id']) ?>
                                        <?= htmlspecialchars($asset['a_name']) ?>
                                    </div>
                                    
                                    <div class="asset-detail">
                                        <span class="asset-label">ชื่อสาขา:</span>
                                        <a href="report.php?as_id=<?= $asset['as_id'] ?>" class="text-decoration-none">
                                            <?= htmlspecialchars($asset['as_name']) ?>
                                        </a>
                                    </div>
                                    
                                    <div class="asset-detail">
                                        <span class="asset-label">รหัสทรัพย์สินใหม่:</span>
                                        <?= htmlspecialchars($asset['as_code_new']) ?>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="asset-detail">
                                        <span class="asset-label">รหัสทรัพย์สินเก่า:</span>
                                        <?= htmlspecialchars($asset['as_code_old']) ?>
                                    </div>
                                    
                                    <div class="asset-detail">
                                        <span class="asset-label">วันที่รับเข้า:</span>
                                        <?= htmlspecialchars($asset['as_day']) ?>
                                    </div>
                                    
                                    <div class="asset-detail">
                                        <span class="asset-label">ราคาคงเหลือ:</span>
                                        <?= number_format($asset['as_price'], 2) ?> บาท
                                    </div>
                                    
                                    <div class="asset-detail">
                                        <span class="asset-label">รายการทรัพย์สิน:</span>
                                        <?= htmlspecialchars($asset['as_list']) ?>
                                    </div>
                                </div>
                            </div>
                        <?php } else { ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> ไม่พบข้อมูลทรัพย์สินที่ค้นหา
                            </div>
                        <?php } ?>
                    </div>
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Modals -->
    <!-- Add License Modal -->
    <div class="modal fade" id="addLicenseModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> เพิ่มข้อมูล License</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="licenseForm" method="post">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">SN Notebook</label>
                            <input type="text" name="nb_sn_nb" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">รหัสพนักงานรับเข้า</label>
                            <input type="text" name="l_id" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">ชื่อผู้ใช้เครื่อง</label>
                            <input type="text" name="nb_name" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Mail Office</label>
                            <input type="text" name="nb_email" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">รหัสเข้า Mail Office</label>
                            <input type="text" name="nb_pass_email" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Key Office</label>
                            <input type="text" name="nb_key_off" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Carton No</label>
                            <input type="text" name="nb_cn_win" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                        <button type="submit" name="insert" class="btn btn-primary">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Asset Detail Modal -->
    <div class="modal fade" id="assetDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-info-circle"></i> รายละเอียดทรัพย์สิน</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="assetDetailContent">
                    <!-- Content loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
    $(document).ready(function() {
        // Handle license form submission
        $('#licenseForm').on('submit', function(e) {
            e.preventDefault();
            
            $.ajax({
                url: "save_nb.php",
                method: "POST",
                data: $(this).serialize(),
                beforeSend: function() {
                    $('#licenseForm button[type="submit"]').html('<i class="fas fa-spinner fa-spin"></i> กำลังบันทึก...');
                },
                success: function(response) {
                    $('#addLicenseModal').modal('hide');
                    $('#licenseForm')[0].reset();
                    // Reload or update page as needed
                    location.reload();
                },
                error: function() {
                    alert('เกิดข้อผิดพลาดในการบันทึกข้อมูล');
                }
            });
        });
        
        // View asset details
        $(document).on('click', '.view-asset', function() {
            var assetId = $(this).data('id');
            
            $.ajax({
                url: "asset_detail.php",
                method: "POST",
                data: { id: assetId },
                success: function(data) {
                    $('#assetDetailContent').html(data);
                    $('#assetDetailModal').modal('show');
                }
            });
        });
    });
    </script>
</body>
</html>