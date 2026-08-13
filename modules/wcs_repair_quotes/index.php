<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$pageTitle = 'บันทึกใบเสนอราคางานซ่อม WCS';

if (!function_exists('wcsE')) {
    function wcsE($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wcsCurrentUser')) {
    function wcsCurrentUser(): string
    {
        $name = trim((string)($_SESSION['full_name'] ?? ''));
        if ($name === '' && !empty($_SESSION['user']) && is_array($_SESSION['user'])) {
            $name = trim((string)($_SESSION['user']['full_name'] ?? ''));
        }
        return $name !== '' ? $name : trim((string)($_SESSION['employee_code'] ?? 'IT'));
    }
}

if (!function_exists('wcsTableExists')) {
    function wcsTableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name');
        $stmt->execute([':table_name' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$tablesReady = wcsTableExists($pdo, 'wcs_repair_quotes') && wcsTableExists($pdo, 'wcs_repair_quote_items');
$attachmentsReady = $tablesReady && wcsTableExists($pdo, 'wcs_repair_quote_attachments');
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablesReady) {
    try {
        if (!hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('CSRF Token ไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองใหม่');
        }

        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'save') {
            $quoteId = max(0, (int)($_POST['quote_id'] ?? 0));
            $repairJobNo = trim((string)($_POST['repair_job_no'] ?? ''));
            $quoteDate = trim((string)($_POST['quote_date'] ?? ''));
            $branchName = trim((string)($_POST['branch_name'] ?? ''));
            $assetCode = trim((string)($_POST['asset_code'] ?? ''));
            $printerModel = trim((string)($_POST['printer_model'] ?? ''));
            $serialNumber = trim((string)($_POST['serial_number'] ?? ''));
            $remark = trim((string)($_POST['remark'] ?? ''));
            $productCodes = $_POST['product_code'] ?? [];
            $descriptions = $_POST['repair_description'] ?? [];
            $quantities = $_POST['quantity'] ?? [];
            $unitPrices = $_POST['unit_price'] ?? [];
            $importedAttachmentsJson = trim((string)($_POST['imported_attachments'] ?? ''));
            $importedAttachments = $importedAttachmentsJson !== '' ? json_decode($importedAttachmentsJson, true) : [];
            if (!is_array($importedAttachments)) {
                $importedAttachments = [];
            }

            $importQueueRemainingJson = trim((string)($_POST['import_queue_remaining'] ?? ''));
            $importQueueRemaining = $importQueueRemainingJson !== '' ? json_decode($importQueueRemainingJson, true) : [];
            if (!is_array($importQueueRemaining)) {
                $importQueueRemaining = [];
            }

            if ($repairJobNo === '' || $quoteDate === '' || $branchName === '' || $assetCode === '' || $printerModel === '' || $serialNumber === '') {
                throw new RuntimeException('กรุณากรอกเลขที่งานซ่อม วันที่ สาขาที่ซ่อม รหัสทรัพย์สิน เครื่องปริ้น และ Serial Number ให้ครบ');
            }

            $items = [];
            $subtotal = 0.00;
            $rowCount = max(count($productCodes), count($descriptions), count($quantities), count($unitPrices));
            for ($i = 0; $i < $rowCount; $i++) {
                $productCode = trim((string)($productCodes[$i] ?? ''));
                $description = trim((string)($descriptions[$i] ?? ''));
                $quantity = max(0, (float)($quantities[$i] ?? 0));
                $unitPrice = max(0, (float)($unitPrices[$i] ?? 0));

                if ($productCode === '' && $description === '' && $quantity <= 0 && $unitPrice <= 0) {
                    continue;
                }
                if ($productCode === '' || $description === '' || $quantity <= 0) {
                    throw new RuntimeException('กรุณากรอกรหัสสินค้า รายการซ่อม และจำนวนในทุกรายการ');
                }

                $lineAmount = round($quantity * $unitPrice, 2);
                $subtotal += $lineAmount;
                $items[] = compact('productCode', 'description', 'quantity', 'unitPrice', 'lineAmount');
            }

            if (!$items) {
                throw new RuntimeException('กรุณาเพิ่มรายการซ่อมอย่างน้อย 1 รายการ');
            }

            $vatRate = 7.00;
            $vatAmount = round($subtotal * ($vatRate / 100), 2);
            $totalAmount = round($subtotal + $vatAmount, 2);
            $pdo->beginTransaction();

            if ($quoteId > 0) {
                $stmt = $pdo->prepare('UPDATE wcs_repair_quotes SET repair_job_no=:repair_job_no, quote_date=:quote_date, branch_name=:branch_name, asset_code=:asset_code, printer_model=:printer_model, serial_number=:serial_number, remark=:remark, subtotal=:subtotal, vat_rate=:vat_rate, vat_amount=:vat_amount, total_amount=:total_amount, updated_by=:updated_by, updated_at=NOW() WHERE id=:id');
                $stmt->execute([
                    ':repair_job_no' => $repairJobNo,
                    ':quote_date' => $quoteDate,
                    ':branch_name' => $branchName,
                    ':asset_code' => $assetCode,
                    ':printer_model' => $printerModel,
                    ':serial_number' => $serialNumber,
                    ':remark' => $remark !== '' ? $remark : null,
                    ':subtotal' => $subtotal,
                    ':vat_rate' => $vatRate,
                    ':vat_amount' => $vatAmount,
                    ':total_amount' => $totalAmount,
                    ':updated_by' => wcsCurrentUser(),
                    ':id' => $quoteId,
                ]);
                $pdo->prepare('DELETE FROM wcs_repair_quote_items WHERE quote_id=:quote_id')->execute([':quote_id' => $quoteId]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO wcs_repair_quotes (repair_job_no, quote_date, branch_name, asset_code, printer_model, serial_number, remark, subtotal, vat_rate, vat_amount, total_amount, created_by) VALUES (:repair_job_no, :quote_date, :branch_name, :asset_code, :printer_model, :serial_number, :remark, :subtotal, :vat_rate, :vat_amount, :total_amount, :created_by)');
                $stmt->execute([
                    ':repair_job_no' => $repairJobNo,
                    ':quote_date' => $quoteDate,
                    ':branch_name' => $branchName,
                    ':asset_code' => $assetCode,
                    ':printer_model' => $printerModel,
                    ':serial_number' => $serialNumber,
                    ':remark' => $remark !== '' ? $remark : null,
                    ':subtotal' => $subtotal,
                    ':vat_rate' => $vatRate,
                    ':vat_amount' => $vatAmount,
                    ':total_amount' => $totalAmount,
                    ':created_by' => wcsCurrentUser(),
                ]);
                $quoteId = (int)$pdo->lastInsertId();
            }

            if ($attachmentsReady && !empty($importedAttachments)) {
                $oldAttachmentStmt = $pdo->prepare('SELECT file_path FROM wcs_repair_quote_attachments WHERE quote_id=:quote_id');
                $oldAttachmentStmt->execute([':quote_id' => $quoteId]);
                $oldAttachmentPaths = $oldAttachmentStmt->fetchAll(PDO::FETCH_COLUMN);
                $pdo->prepare('DELETE FROM wcs_repair_quote_attachments WHERE quote_id=:quote_id')->execute([':quote_id' => $quoteId]);

                $attachmentStmt = $pdo->prepare('INSERT INTO wcs_repair_quote_attachments (quote_id, sheet_name, file_name, file_path, mime_type, file_size, sort_order, source_file_name) VALUES (:quote_id, :sheet_name, :file_name, :file_path, :mime_type, :file_size, :sort_order, :source_file_name)');
                foreach ($importedAttachments as $attachment) {
                    $filePath = trim((string)($attachment['file_path'] ?? ''));
                    // ยอมรับเฉพาะไฟล์รูปภายในโฟลเดอร์ WCS และป้องกัน Path Traversal
                    if (
                        $filePath === ''
                        || strpos($filePath, '..') !== false
                        || !preg_match('#^uploads/wcs_repair_quotes/[A-Za-z0-9_\-/]+\.(?:png|jpe?g|gif|webp|bmp)$#i', $filePath)
                    ) {
                        continue;
                    }
                    $absolutePath = dirname(__DIR__, 2) . '/' . $filePath;
                    if (!is_file($absolutePath)) {
                        continue;
                    }
                    $attachmentStmt->execute([
                        ':quote_id' => $quoteId,
                        ':sheet_name' => trim((string)($attachment['sheet_name'] ?? 'เอกสารแนบ')),
                        ':file_name' => basename($filePath),
                        ':file_path' => $filePath,
                        ':mime_type' => trim((string)($attachment['mime_type'] ?? 'image/png')),
                        ':file_size' => (int)filesize($absolutePath),
                        ':sort_order' => max(0, (int)($attachment['sort_order'] ?? 0)),
                        ':source_file_name' => trim((string)($attachment['source_file_name'] ?? '')) ?: null,
                    ]);
                }

                foreach ($oldAttachmentPaths as $oldPath) {
                    $oldPath = trim((string)$oldPath);
                    $stillUsed = false;
                    foreach ($importedAttachments as $attachment) {
                        if ($oldPath !== '' && $oldPath === trim((string)($attachment['file_path'] ?? ''))) {
                            $stillUsed = true;
                            break;
                        }
                    }
                    if (!$stillUsed && preg_match('#^uploads/wcs_repair_quotes/#', $oldPath)) {
                        $oldAbsolutePath = dirname(__DIR__, 2) . '/' . $oldPath;
                        if (is_file($oldAbsolutePath)) {
                            @unlink($oldAbsolutePath);
                        }
                    }
                }
            }

            $itemStmt = $pdo->prepare('INSERT INTO wcs_repair_quote_items (quote_id, product_code, repair_description, quantity, unit_price, line_amount) VALUES (:quote_id, :product_code, :repair_description, :quantity, :unit_price, :line_amount)');
            foreach ($items as $item) {
                $itemStmt->execute([
                    ':quote_id' => $quoteId,
                    ':product_code' => $item['productCode'],
                    ':repair_description' => $item['description'],
                    ':quantity' => $item['quantity'],
                    ':unit_price' => $item['unitPrice'],
                    ':line_amount' => $item['lineAmount'],
                ]);
            }

            $pdo->commit();
            $_SESSION['wcs_success'] = 'บันทึกใบเสนอราคางานซ่อม WCS เรียบร้อยแล้ว';

            if (!empty($importQueueRemaining)) {
                $_SESSION['wcs_import_queue'] = array_values($importQueueRemaining);
                header('Location: index.php?continue_import=1');
            } else {
                unset($_SESSION['wcs_import_queue']);
                header('Location: index.php');
            }
            exit;
        }

        if ($action === 'delete') {
            $quoteId = max(0, (int)($_POST['quote_id'] ?? 0));
            if ($quoteId <= 0) {
                throw new RuntimeException('ไม่พบรายการที่ต้องการลบ');
            }
            if ($attachmentsReady) {
                $deleteAttachmentStmt = $pdo->prepare('SELECT file_path FROM wcs_repair_quote_attachments WHERE quote_id=:quote_id');
                $deleteAttachmentStmt->execute([':quote_id' => $quoteId]);
                foreach ($deleteAttachmentStmt->fetchAll(PDO::FETCH_COLUMN) as $attachmentPath) {
                    $attachmentPath = trim((string)$attachmentPath);
                    if (preg_match('#^uploads/wcs_repair_quotes/#', $attachmentPath)) {
                        $absolutePath = dirname(__DIR__, 2) . '/' . $attachmentPath;
                        if (is_file($absolutePath)) {
                            @unlink($absolutePath);
                        }
                    }
                }
            }
            $pdo->prepare('DELETE FROM wcs_repair_quotes WHERE id=:id')->execute([':id' => $quoteId]);
            $_SESSION['wcs_success'] = 'ลบใบเสนอราคาเรียบร้อยแล้ว';
            header('Location: index.php');
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$message = (string)($_SESSION['wcs_success'] ?? '');
unset($_SESSION['wcs_success']);

$pendingImportQueue = $_SESSION['wcs_import_queue'] ?? [];
unset($_SESSION['wcs_import_queue']);
if (!is_array($pendingImportQueue)) {
    $pendingImportQueue = [];
}

$keyword = trim((string)($_GET['q'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$quotes = [];
$kpi = ['total' => 0, 'month' => 0, 'subtotal' => 0, 'vat' => 0, 'total_amount' => 0];

if ($tablesReady) {
    $kpi['total'] = (int)$pdo->query('SELECT COUNT(*) FROM wcs_repair_quotes')->fetchColumn();
    $kpi['month'] = (int)$pdo->query("SELECT COUNT(*) FROM wcs_repair_quotes WHERE DATE_FORMAT(quote_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')")->fetchColumn();
    $sumRow = $pdo->query('SELECT COALESCE(SUM(subtotal),0) subtotal, COALESCE(SUM(vat_amount),0) vat, COALESCE(SUM(total_amount),0) total_amount FROM wcs_repair_quotes')->fetch(PDO::FETCH_ASSOC);
    $kpi['subtotal'] = (float)$sumRow['subtotal'];
    $kpi['vat'] = (float)$sumRow['vat'];
    $kpi['total_amount'] = (float)$sumRow['total_amount'];

    $where = [];
    $params = [];
    if ($keyword !== '') {
        $where[] = '(q.repair_job_no LIKE :keyword_job_no
            OR q.branch_name LIKE :keyword_branch_name
            OR q.asset_code LIKE :keyword_asset_code
            OR q.printer_model LIKE :keyword_printer_model
            OR q.serial_number LIKE :keyword_serial_number
            OR q.remark LIKE :keyword_remark
            OR EXISTS (
                SELECT 1
                FROM wcs_repair_quote_items i2
                WHERE i2.quote_id = q.id
                  AND (
                      i2.product_code LIKE :keyword_product_code
                      OR i2.repair_description LIKE :keyword_repair_description
                  )
            ))';

        $keywordLike = '%' . $keyword . '%';
        $params[':keyword_job_no'] = $keywordLike;
        $params[':keyword_branch_name'] = $keywordLike;
        $params[':keyword_asset_code'] = $keywordLike;
        $params[':keyword_printer_model'] = $keywordLike;
        $params[':keyword_serial_number'] = $keywordLike;
        $params[':keyword_remark'] = $keywordLike;
        $params[':keyword_product_code'] = $keywordLike;
        $params[':keyword_repair_description'] = $keywordLike;
    }
    if ($dateFrom !== '') {
        $where[] = 'q.quote_date >= :date_from';
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'q.quote_date <= :date_to';
        $params[':date_to'] = $dateTo;
    }
    $sql = 'SELECT q.*, COUNT(i.id) item_count FROM wcs_repair_quotes q LEFT JOIN wcs_repair_quote_items i ON i.quote_id=q.id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' GROUP BY q.id ORDER BY q.quote_date DESC, q.id DESC LIMIT 300';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $itemStmt = $pdo->prepare('SELECT id, product_code, repair_description, quantity, unit_price, line_amount FROM wcs_repair_quote_items WHERE quote_id=:quote_id ORDER BY id ASC');
    foreach ($quotes as &$quote) {
        $itemStmt->execute([':quote_id' => $quote['id']]);
        $quote['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($quote);

    if ($attachmentsReady && !empty($quotes)) {
        $attachmentStmt = $pdo->prepare('SELECT id, sheet_name, file_name, file_path, mime_type, file_size, sort_order, source_file_name FROM wcs_repair_quote_attachments WHERE quote_id=:quote_id ORDER BY sheet_name ASC, sort_order ASC, id ASC');
        foreach ($quotes as &$quote) {
            $attachmentStmt->execute([':quote_id' => $quote['id']]);
            $quote['attachments'] = $attachmentStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($quote);
    } else {
        foreach ($quotes as &$quote) {
            $quote['attachments'] = [];
        }
        unset($quote);
    }
}

require_once __DIR__ . '/../../includes/header.php';

require_login();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_permission('wcs_quote.manage');
} else {
    require_permission('wcs_quote.view');
}

?>
<style>
.wcs-page{padding:0;display:flex;flex-direction:column;min-height:calc(100vh - 96px)}.wcs-hero{background:linear-gradient(135deg,#0b3c68,#1769aa);border-radius:18px;color:#fff;padding:18px 20px;margin-bottom:14px;box-shadow:0 12px 30px rgba(15,76,129,.18)}
.wcs-hero h1{font-size:1.3rem;font-weight:900;margin:0 0 4px}.wcs-hero p{font-size:.82rem;margin:0;opacity:.88}.wcs-add-btn{color:#dc2626!important;border:2px solid #dc2626!important;background:#fff!important;border-radius:10px!important;font-weight:900!important;white-space:nowrap;padding:.55rem .9rem!important;font-size:.92rem!important;line-height:1.5!important;box-shadow:0 4px 12px rgba(220,38,38,.22)!important}.wcs-add-btn:hover,.wcs-add-btn:focus{color:#fff!important;background:#dc2626!important;border-color:#b91c1c!important;box-shadow:0 6px 16px rgba(220,38,38,.32)!important}.wcs-kpi{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:14px}.wcs-kpi-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px 14px;box-shadow:0 5px 16px rgba(15,23,42,.05)}.wcs-kpi-label{font-size:.72rem;color:#64748b;font-weight:800}.wcs-kpi-value{font-size:1.12rem;color:#0f4c81;font-weight:900;margin-top:3px}.wcs-panel{background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 6px 20px rgba(15,23,42,.05);overflow:hidden;display:flex;flex-direction:column;flex:1;min-height:0;margin-bottom:0!important}.wcs-panel-head{padding:12px 14px;border-bottom:1px solid #e8eef4;background:#fbfdff}.wcs-filter{display:grid;grid-template-columns:minmax(220px,1fr) 150px 150px 95px 85px;gap:8px;align-items:end}.wcs-table{font-size:.75rem;margin:0}.wcs-table th{background:#f8fafc;color:#0f172a;font-weight:900;white-space:nowrap;position:sticky;top:0;z-index:1}.wcs-table td{vertical-align:middle}.wcs-money{font-weight:900;color:#9a3412;text-align:right;white-space:nowrap}.wcs-job-link{border:0;background:transparent;color:#0f4c81;font-weight:900;padding:0;border-bottom:1px dashed #0f4c81}.wcs-modal .modal-dialog{max-width:1080px}.wcs-modal .modal-content{border:0;border-radius:18px;overflow:hidden}.wcs-modal .modal-header{background:linear-gradient(135deg,#eff6ff,#fff)}.wcs-item-table th{font-size:.72rem;background:#f8fafc}.wcs-item-table td{padding:.35rem}.wcs-item-table .form-control{min-height:36px;font-size:.78rem}.wcs-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.wcs-note-box{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px}.wcs-note-box textarea{min-height:82px}.wcs-summary-item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:9px 12px}.wcs-summary-item.total{background:#eff6ff;border-color:#bfdbfe}.wcs-summary-label{font-size:.7rem;color:#64748b;font-weight:800}.wcs-summary-value{font-size:1rem;font-weight:900;text-align:right}.wcs-detail-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.wcs-detail-item{border:1px solid #e2e8f0;border-radius:10px;padding:9px;background:#f8fafc}.wcs-detail-label{font-size:.68rem;color:#64748b;font-weight:800}.wcs-detail-value{font-size:.82rem;font-weight:800;overflow-wrap:anywhere}.wcs-detail-items{font-size:.75rem}.wcs-detail-items th{background:#f8fafc}.wcs-empty{padding:34px;text-align:center;color:#64748b}@media(max-width:1200px){.wcs-kpi{grid-template-columns:repeat(3,1fr)}.wcs-filter{grid-template-columns:1fr 140px 140px 90px 80px}}@media(max-width:768px){.wcs-kpi{grid-template-columns:1fr 1fr}.wcs-filter{grid-template-columns:1fr}.wcs-detail-grid{grid-template-columns:1fr 1fr}.wcs-summary{grid-template-columns:1fr}}
.wcs-attachment-preview{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.wcs-attachment-card{border:1px solid #dbe5ee;border-radius:12px;background:#fff;overflow:hidden}.wcs-attachment-card img{width:100%;height:150px;object-fit:cover;display:block}.wcs-attachment-meta{padding:8px 10px}.wcs-attachment-sheet{font-size:.76rem;font-weight:900;color:#0f4c81}.wcs-attachment-name{font-size:.68rem;color:#64748b;overflow-wrap:anywhere}.wcs-detail-attachments{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.wcs-detail-attachment{border:1px solid #dbe5ee;border-radius:12px;background:#fff;overflow:hidden;padding:0;text-align:left}.wcs-detail-attachment img{width:100%;height:170px;object-fit:cover;display:block}.wcs-detail-attachment span{display:block;padding:8px 10px;font-size:.74rem;font-weight:900;color:#0f4c81}.wcs-image-viewer .modal-dialog{max-width:1100px}.wcs-image-viewer img{max-width:100%;max-height:78vh;display:block;margin:auto}@media(max-width:768px){.wcs-attachment-preview,.wcs-detail-attachments{grid-template-columns:1fr 1fr}}
.wcs-import-queue{display:grid;gap:8px}.wcs-import-item{border:1px solid #dbe5ee;border-radius:12px;background:#fff;padding:10px 12px;display:flex;justify-content:space-between;align-items:center;gap:10px}.wcs-import-item-title{font-weight:900;color:#0f4c81;font-size:.8rem}.wcs-import-item-meta{font-size:.7rem;color:#64748b}.wcs-import-item.error{border-color:#fecaca;background:#fff7f7}.wcs-import-item.success{border-color:#bbf7d0;background:#f7fff9}
.wcs-table-wrap{flex:1;min-height:320px;max-height:none;overflow:auto}
@media(max-height:820px) and (min-width:769px){.wcs-page{min-height:calc(100vh - 76px)}.wcs-table-wrap{min-height:360px}}
</style>
<div class="wcs-page">
    <div class="wcs-hero d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div><h1>บันทึกใบเสนอราคางานซ่อม WCS</h1>
        <!-- <p>จัดเก็บใบเสนอราคาซ่อมเครื่องพิมพ์จากบริษัท เวิลด์ไวด์ คอม เซอร์วิส จำกัด</p> -->
    </div>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-outline-light fw-bold" type="button" data-bs-toggle="modal" data-bs-target="#wcsImportModal">นำเข้าจาก Excel / PDF</button>
            <button class="btn btn-light fw-bold wcs-add-btn" type="button" data-bs-toggle="modal" data-bs-target="#wcsFormModal" id="btnAddQuote">+ เพิ่มใบเสนอราคา</button>
        </div>
    </div>

    <?php if (!$tablesReady): ?><div class="alert alert-warning"><strong>ยังไม่พบตารางฐานข้อมูล</strong><br>ให้นำไฟล์ <code>modules/wcs_repair_quotes/install.sql</code> ไป Import ในฐานข้อมูลก่อนใช้งาน</div><?php elseif (!$attachmentsReady): ?><div class="alert alert-warning"><strong>ยังไม่พบตารางเก็บรูปภาพจาก Excel</strong><br>ให้นำไฟล์ <code>modules/wcs_repair_quotes/upgrade_add_excel_images.sql</code> ไป Import ก่อนใช้งานการนำเข้ารูปภาพ</div><?php endif; ?>
    <?php if ($message !== ''): ?><div class="alert alert-success py-2"><?php echo wcsE($message); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger py-2"><?php echo wcsE($error); ?></div><?php endif; ?>

    <div class="wcs-kpi">
        <div class="wcs-kpi-card"><div class="wcs-kpi-label">ใบเสนอราคาทั้งหมด</div><div class="wcs-kpi-value"><?php echo number_format($kpi['total']); ?> รายการ</div></div>
        <div class="wcs-kpi-card"><div class="wcs-kpi-label">ใบเสนอราคาเดือนนี้</div><div class="wcs-kpi-value"><?php echo number_format($kpi['month']); ?> รายการ</div></div>
        <div class="wcs-kpi-card"><div class="wcs-kpi-label">ยอดก่อนภาษี</div><div class="wcs-kpi-value"><?php echo number_format($kpi['subtotal'], 2); ?></div></div>
        <div class="wcs-kpi-card"><div class="wcs-kpi-label">ภาษีมูลค่าเพิ่ม 7%</div><div class="wcs-kpi-value"><?php echo number_format($kpi['vat'], 2); ?></div></div>
        <div class="wcs-kpi-card"><div class="wcs-kpi-label">จำนวนเงินรวม</div><div class="wcs-kpi-value"><?php echo number_format($kpi['total_amount'], 2); ?> บาท</div></div>
    </div>

    <div class="wcs-panel">
        <div class="wcs-panel-head">
            <form method="get" class="wcs-filter">
                <div><label class="form-label small fw-bold">ค้นหา</label><input type="search" name="q" class="form-control" value="<?php echo wcsE($keyword); ?>" placeholder="เลขที่งานซ่อม, สาขา, รหัสทรัพย์สิน, เครื่องปริ้น, Serial Number"></div>
                <div><label class="form-label small fw-bold">วันที่เริ่มต้น</label><input type="date" name="date_from" class="form-control" value="<?php echo wcsE($dateFrom); ?>"></div>
                <div><label class="form-label small fw-bold">วันที่สิ้นสุด</label><input type="date" name="date_to" class="form-control" value="<?php echo wcsE($dateTo); ?>"></div>
                <button class="btn btn-primary" type="submit">ค้นหา</button>
                <a class="btn btn-outline-secondary" href="index.php">ล้างค่า</a>
            </form>
        </div>
        <div class="table-responsive wcs-table-wrap">
            <table class="table table-hover table-bordered wcs-table">
                <thead><tr><th>เลขที่งานซ่อม</th><th>วันที่</th><th>สาขาที่ซ่อม</th><th>รหัสทรัพย์สิน</th><th class="text-center">รายการซ่อม</th><th class="text-end">ก่อนภาษี</th><th class="text-end">VAT 7%</th><th class="text-end">จำนวนเงิน</th><th class="text-center">จัดการ</th></tr></thead>
                <tbody>
                <?php if (!$quotes): ?><tr><td colspan="9" class="wcs-empty">ยังไม่มีข้อมูลใบเสนอราคางานซ่อม WCS</td></tr><?php else: foreach ($quotes as $quote): ?>
                    <?php $quoteJson = json_encode($quote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
                    <tr>
                        <td><button type="button" class="wcs-job-link js-view-quote" data-quote="<?php echo wcsE($quoteJson); ?>"><?php echo wcsE($quote['repair_job_no']); ?></button></td>
                        <td><?php echo wcsE(date('d/m/Y', strtotime($quote['quote_date']))); ?></td>
                        <td><?php echo wcsE($quote['branch_name']); ?></td>
                        <td class="fw-bold text-primary"><?php echo wcsE($quote['asset_code']); ?></td>
                        <td class="text-center"><span class="badge text-bg-info"><?php echo number_format((int)$quote['item_count']); ?> รายการ</span></td>
                        <td class="wcs-money"><?php echo number_format((float)$quote['subtotal'], 2); ?></td>
                        <td class="wcs-money"><?php echo number_format((float)$quote['vat_amount'], 2); ?></td>
                        <td class="wcs-money"><?php echo number_format((float)$quote['total_amount'], 2); ?></td>
                        <td class="text-center text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-warning js-edit-quote" data-quote="<?php echo wcsE($quoteJson); ?>"><svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" role="img" aria-label="แก้ไข"><path d="M12.146.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-10 10A.5.5 0 0 1 5.5 14H2a.5.5 0 0 1-.5-.5V10a.5.5 0 0 1 .146-.354zM11.207 2.5 13.5 4.793 14.793 3.5 12.5 1.207zM12.793 5.5 10.5 3.207 4 9.707V10h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.293zM3.5 10.207 2.5 11.207V13h1.793l1-1H5.5v-.5H5a.5.5 0 0 1-.5-.5v-.5H4a.5.5 0 0 1-.5-.5z"/></svg></button>
                            <form method="post" class="d-inline" onsubmit="return confirm('ยืนยันลบใบเสนอราคา <?php echo wcsE($quote['repair_job_no']); ?> ?');"><input type="hidden" name="csrf_token" value="<?php echo wcsE($_SESSION['csrf_token']); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="quote_id" value="<?php echo (int)$quote['id']; ?>"><button class="btn btn-sm btn-outline-danger" type="submit" title="ลบ" aria-label="ลบ"><svg class="action-icon" width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/><path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1 0-2H5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1h2.5a1 1 0 0 1 1 1M4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM6.5 2a.5.5 0 0 0-.5.5V3h4v-.5a.5.5 0 0 0-.5-.5z"/></svg></button></form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade wcs-modal" id="wcsImportModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
<div class="modal-header"><div><h5 class="modal-title fw-bold">นำเข้าใบเสนอราคาจาก Excel / PDF</h5><div class="small text-muted">เลือกไฟล์ .xlsx หรือ .pdf ได้สูงสุด 5 ไฟล์จากบริษัท เวิลด์ไวด์ คอม เซอร์วิส จำกัด</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body bg-light">
    <div class="card border-0 shadow-sm"><div class="card-body">
        <label class="form-label fw-bold">ไฟล์ใบเสนอราคา Excel / PDF <span class="text-danger">*</span></label>
        <input type="file" id="wcsExcelFile" class="form-control" accept=".xlsx,.pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/pdf" multiple>
        <div class="form-text">เลือกได้สูงสุด 5 ไฟล์ รองรับ Excel (.xlsx) และ PDF ที่มีข้อความ ระบบจะแสดงข้อมูลให้ตรวจสอบและแก้ไขก่อนบันทึกทีละใบ</div>
        <div id="wcsImportStatus" class="alert d-none mt-3 mb-0"></div><div id="wcsImportQueue" class="mt-3 d-none"></div>
    </div></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button><button type="button" class="btn btn-primary px-4 fw-bold" id="btnReadExcel">อ่านข้อมูลจากไฟล์</button></div>
</div></div></div>

<div class="modal fade wcs-modal" id="wcsFormModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><form method="post" class="modal-content" id="wcsForm">
<div class="modal-header"><div><h5 class="modal-title fw-bold" id="wcsFormTitle">เพิ่มใบเสนอราคางานซ่อม WCS</h5><div class="small text-muted">บันทึกข้อมูลจากใบเสนอราคาที่ได้รับทาง LINE</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body bg-light">
<input type="hidden" name="csrf_token" value="<?php echo wcsE($_SESSION['csrf_token']); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="quote_id" id="quote_id" value=""><input type="hidden" name="imported_attachments" id="imported_attachments" value=""><input type="hidden" name="import_queue_remaining" id="import_queue_remaining" value="">
<div class="card border-0 shadow-sm mb-3"><div class="card-body"><div class="row g-3">
<div class="col-md-3"><label class="form-label fw-bold">เลขที่งานซ่อม <span class="text-danger">*</span></label><input type="text" name="repair_job_no" id="repair_job_no" class="form-control" required></div>
<div class="col-md-3"><label class="form-label fw-bold">วันที่ <span class="text-danger">*</span></label><input type="date" name="quote_date" id="quote_date" class="form-control" required></div>
<div class="col-md-3"><label class="form-label fw-bold">สาขาที่ซ่อม <span class="text-danger">*</span></label><input type="text" name="branch_name" id="branch_name" class="form-control" required></div>
<div class="col-md-3"><label class="form-label fw-bold">รหัสทรัพย์สิน <span class="text-danger">*</span></label><input type="text" name="asset_code" id="asset_code" class="form-control" required></div>
<div class="col-md-6"><label class="form-label fw-bold">เครื่องปริ้น <span class="text-danger">*</span></label><select name="printer_model" id="printer_model" class="form-select" required><option value="">-- เลือกรุ่นเครื่องปริ้น --</option><option value="HP LaserJet Pro M402dn">HP LaserJet Pro M402dn</option><option value="HP LaserJet Pro M404dn">HP LaserJet Pro M404dn</option><option value="HP LaserJet Pro MFP M426">HP LaserJet Pro MFP M426</option><option value="HP LaserJet Pro MFP M428fdn">HP LaserJet Pro MFP M428fdn</option><option value="HP LaserJet MFP M430 series">HP LaserJet MFP M430 series</option><option value="Brother DCP-L5600DN">Brother DCP-L5600DN</option><option value="Brother MFC-L5900DW">Brother MFC-L5900DW</option><option value="Brother MFC-L5915DW">Brother MFC-L5915DW</option></select></div>
<div class="col-md-6"><label class="form-label fw-bold">Serial Number <span class="text-danger">*</span></label><input type="text" name="serial_number" id="serial_number" class="form-control" required></div>
</div></div></div>
<div class="card border-0 shadow-sm"><div class="card-header bg-white d-flex justify-content-between align-items-center"><strong>รายการซ่อม</strong><button type="button" class="btn btn-sm btn-outline-primary" id="addItemRow">+ เพิ่มรายการ</button></div><div class="table-responsive"><table class="table table-bordered wcs-item-table mb-0"><thead><tr><th style="width:16%">รหัสสินค้า</th><th>รายการซ่อม</th><th style="width:9%">จำนวน</th><th style="width:14%">ราคาต่อหน่วย</th><th style="width:13%">ภาษี 7%</th><th style="width:14%">จำนวนเงิน</th><th style="width:50px"></th></tr></thead><tbody id="itemRows"></tbody></table></div></div>
<div class="wcs-note-box mt-3"><label class="form-label fw-bold">หมายเหตุ</label><textarea name="remark" id="remark" class="form-control" placeholder="รายละเอียดเพิ่มเติมเกี่ยวกับใบเสนอราคาหรืองานซ่อม"></textarea></div><div id="importedAttachmentSection" class="card border-0 shadow-sm mt-3 d-none"><div class="card-header bg-white"><strong>รูปภาพและเอกสารที่อ่านจากไฟล์</strong><div class="small text-muted">หมายเลขเครื่อง, รูปอะไหล่เสีย และใบรายงาน</div></div><div class="card-body"><div id="importedAttachmentPreview" class="wcs-attachment-preview"></div></div></div><div class="wcs-summary mt-3"><div class="wcs-summary-item"><div class="wcs-summary-label">ยอดก่อนภาษี</div><div class="wcs-summary-value" id="summarySubtotal">0.00 บาท</div></div><div class="wcs-summary-item"><div class="wcs-summary-label">ภาษีมูลค่าเพิ่ม 7%</div><div class="wcs-summary-value" id="summaryVat">0.00 บาท</div></div><div class="wcs-summary-item total"><div class="wcs-summary-label">จำนวนเงินรวม</div><div class="wcs-summary-value text-primary" id="summaryTotal">0.00 บาท</div></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button><button type="submit" class="btn btn-success px-4 fw-bold">บันทึกข้อมูล</button></div>
</form></div></div>

<div class="modal fade wcs-modal" id="wcsDetailModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title fw-bold">รายละเอียดใบเสนอราคางานซ่อม WCS</h5><div class="small text-muted" id="detailSubtitle">-</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="wcs-detail-grid mb-3"><div class="wcs-detail-item"><div class="wcs-detail-label">เลขที่งานซ่อม</div><div class="wcs-detail-value" id="detailJobNo">-</div></div><div class="wcs-detail-item"><div class="wcs-detail-label">วันที่</div><div class="wcs-detail-value" id="detailDate">-</div></div><div class="wcs-detail-item"><div class="wcs-detail-label">สาขาที่ซ่อม</div><div class="wcs-detail-value" id="detailBranch">-</div></div><div class="wcs-detail-item"><div class="wcs-detail-label">รหัสทรัพย์สิน</div><div class="wcs-detail-value text-primary" id="detailAsset">-</div></div><div class="wcs-detail-item"><div class="wcs-detail-label">เครื่องปริ้น</div><div class="wcs-detail-value" id="detailPrinterModel">-</div></div><div class="wcs-detail-item"><div class="wcs-detail-label">Serial Number</div><div class="wcs-detail-value" id="detailSerialNumber">-</div></div><div class="wcs-detail-item" style="grid-column:span 2"><div class="wcs-detail-label">หมายเหตุ</div><div class="wcs-detail-value" id="detailRemark">-</div></div></div><div class="table-responsive"><table class="table table-bordered wcs-detail-items"><thead><tr><th>รหัสสินค้า</th><th>รายการซ่อม</th><th class="text-end">จำนวน</th><th class="text-end">ราคาต่อหน่วย</th><th class="text-end">ภาษี 7%</th><th class="text-end">จำนวนเงิน</th></tr></thead><tbody id="detailItems"></tbody><tfoot><tr><th colspan="5" class="text-end">ยอดก่อนภาษี</th><th class="text-end" id="detailSubtotal">0.00</th></tr><tr><th colspan="5" class="text-end">ภาษีมูลค่าเพิ่ม 7%</th><th class="text-end" id="detailVat">0.00</th></tr><tr class="table-primary"><th colspan="5" class="text-end">จำนวนเงินรวม</th><th class="text-end" id="detailTotal">0.00</th></tr></tfoot></table></div><div id="detailAttachmentSection" class="mt-3 d-none"><h6 class="fw-bold mb-2">รูปภาพและเอกสารจากไฟล์</h6><div id="detailAttachments" class="wcs-detail-attachments"></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button></div></div></div></div>

<div class="modal fade wcs-image-viewer" id="wcsImageViewerModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title fw-bold" id="imageViewerTitle">รูปภาพเอกสาร</h5><div class="small text-muted" id="imageViewerFileName">-</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><img id="imageViewerImage" src="" alt="รูปภาพเอกสาร WCS"></div><div class="modal-footer"><a id="imageViewerOpenLink" href="#" target="_blank" rel="noopener" class="btn btn-outline-primary">เปิดรูปต้นฉบับ</a><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button></div></div></div></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const formModalEl = document.getElementById('wcsFormModal');
    const formModal = formModalEl && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(formModalEl) : null;
    const itemRows = document.getElementById('itemRows');
    const importModalEl = document.getElementById('wcsImportModal');
    const importModal = importModalEl && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(importModalEl) : null;
    const money = value => Number(value || 0).toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2});
    const baseUrl = <?php echo json_encode($baseUrl ?? '/harddisk_delivery_web'); ?>;
    function attachmentUrl(path) {
        path = String(path || '').replace(/^\/+/, '');
        return baseUrl.replace(/\/$/, '') + '/' + path;
    }
    function setImportedAttachments(attachments) {
        attachments = Array.isArray(attachments) ? attachments : [];
        document.getElementById('imported_attachments').value = JSON.stringify(attachments);
        const section = document.getElementById('importedAttachmentSection');
        const preview = document.getElementById('importedAttachmentPreview');
        preview.innerHTML = attachments.map(function (attachment) {
            return `<div class="wcs-attachment-card"><img src="${escapeHtml(attachmentUrl(attachment.file_path))}" alt="${escapeHtml(attachment.sheet_name || 'เอกสารแนบ')}"><div class="wcs-attachment-meta"><div class="wcs-attachment-sheet">${escapeHtml(attachment.sheet_name || 'เอกสารแนบ')}</div><div class="wcs-attachment-name">${escapeHtml(attachment.file_name || '')}</div></div></div>`;
        }).join('');
        section.classList.toggle('d-none', attachments.length === 0);
    }


    function addRow(item) {
        item = item || {};
        const tr = document.createElement('tr');
        tr.innerHTML = `<td><input type="text" name="product_code[]" class="form-control" value="${escapeHtml(item.product_code || '')}" required></td><td><input type="text" name="repair_description[]" class="form-control" value="${escapeHtml(item.repair_description || '')}" required></td><td><input type="number" name="quantity[]" class="form-control text-end js-calc" min="0.01" step="0.01" value="${item.quantity || 1}" required></td><td><input type="number" name="unit_price[]" class="form-control text-end js-calc" min="0" step="0.01" value="${item.unit_price !== undefined && item.unit_price !== null && Number(item.unit_price) !== 0 ? item.unit_price : ''}" required></td><td><input type="text" class="form-control text-end js-line-vat" value="0.00" readonly></td><td><input type="text" class="form-control text-end js-line-amount" value="0.00" readonly></td><td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger js-remove-row">×</button></td>`;
        itemRows.appendChild(tr);
        calculate();
    }
    function escapeHtml(value) { return String(value ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
    function calculate() {
        let subtotal = 0;
        itemRows.querySelectorAll('tr').forEach(function (row) {
            const quantity = Number(row.querySelector('[name="quantity[]"]').value || 0);
            const unitPrice = Number(row.querySelector('[name="unit_price[]"]').value || 0);
            const line = quantity * unitPrice;
            const lineVat = line * 0.07;
            subtotal += line;
            row.querySelector('.js-line-vat').value = money(lineVat);
            row.querySelector('.js-line-amount').value = money(line + lineVat);
        });
        const vat = subtotal * 0.07;
        document.getElementById('summarySubtotal').textContent = money(subtotal) + ' บาท';
        document.getElementById('summaryVat').textContent = money(vat) + ' บาท';
        document.getElementById('summaryTotal').textContent = money(subtotal + vat) + ' บาท';
    }
    function resetForm() {
        document.getElementById('wcsForm').reset();
        document.getElementById('quote_id').value = '';
        document.getElementById('import_queue_remaining').value = '';
        setImportedAttachments([]);
        document.getElementById('wcsFormTitle').textContent = 'เพิ่มใบเสนอราคางานซ่อม WCS';
        document.getElementById('quote_date').value = new Date().toISOString().slice(0,10);
        itemRows.innerHTML = '';
        addRow();
    }
    document.getElementById('btnAddQuote').addEventListener('click', resetForm);

    const importQueueBox = document.getElementById('wcsImportQueue');
    let importedQuoteQueue = <?php echo json_encode(array_values($pendingImportQueue), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    function fillFormFromImportedQuote(quote, selectedIndex) {
        resetForm();
        const remainingQueue = importedQuoteQueue.filter(function (item, index) {
            return index !== Number(selectedIndex);
        });
        document.getElementById('import_queue_remaining').value = JSON.stringify(remainingQueue);
        document.getElementById('repair_job_no').value = quote.repair_job_no || '';
        document.getElementById('quote_date').value = quote.quote_date || '';
        document.getElementById('branch_name').value = quote.branch_name || '';
        document.getElementById('asset_code').value = quote.asset_code || '';
        document.getElementById('printer_model').value = quote.printer_model || '';
        document.getElementById('serial_number').value = quote.serial_number || '';
        document.getElementById('remark').value = quote.remark || '';
        setImportedAttachments(quote.attachments || []);
        itemRows.innerHTML = '';
        (quote.items || []).forEach(addRow);
        if (!(quote.items || []).length) addRow();
        calculate();
        if (importModal) importModal.hide();
        if (formModal) formModal.show();
    }

    function renderImportQueue(results) {
        importedQuoteQueue = Array.isArray(results) ? results : [];
        importQueueBox.className = importedQuoteQueue.length ? 'mt-3 wcs-import-queue' : 'mt-3 d-none';
        importQueueBox.innerHTML = importedQuoteQueue.map(function (result, index) {
            if (!result.success) {
                return `<div class="wcs-import-item error"><div><div class="wcs-import-item-title">${escapeHtml(result.file_name || 'ไฟล์นำเข้า')}</div><div class="wcs-import-item-meta text-danger">${escapeHtml(result.message || 'อ่านไฟล์ไม่สำเร็จ')}</div></div></div>`;
            }
            const quote = result.data || {};
            return `<div class="wcs-import-item success"><div><div class="wcs-import-item-title">${escapeHtml(quote.repair_job_no || result.file_name || 'ใบเสนอราคา')}</div><div class="wcs-import-item-meta">${escapeHtml(quote.branch_name || '-')} • ${escapeHtml(quote.asset_code || '-')} • ${(quote.items || []).length} รายการ</div></div><button type="button" class="btn btn-sm btn-primary js-open-imported-quote" data-index="${index}">ตรวจสอบและบันทึก</button></div>`;
        }).join('');
    }

    if (importedQuoteQueue.length) {
        renderImportQueue(importedQuoteQueue);
        const statusBox = document.getElementById('wcsImportStatus');
        statusBox.className = 'alert alert-info mt-3 mb-0';
        statusBox.textContent = 'บันทึกสำเร็จแล้ว 1 รายการ เหลืออีก ' + importedQuoteQueue.filter(item => item.success).length + ' รายการให้ตรวจสอบและบันทึก';
        if (<?php echo (($_GET['continue_import'] ?? '') === '1') ? 'true' : 'false'; ?> && importModal) {
            importModal.show();
        }
    }

    importQueueBox.addEventListener('click', function (event) {
        const button = event.target.closest('.js-open-imported-quote');
        if (!button) return;
        const result = importedQuoteQueue[Number(button.dataset.index)];
        if (result && result.success) fillFormFromImportedQuote(result.data || {}, Number(button.dataset.index));
    });

    document.getElementById('btnReadExcel').addEventListener('click', function () {
        const fileInput = document.getElementById('wcsExcelFile');
        const statusBox = document.getElementById('wcsImportStatus');
        const files = Array.from(fileInput.files || []);

        if (!files.length) {
            statusBox.className = 'alert alert-warning mt-3 mb-0';
            statusBox.textContent = 'กรุณาเลือกไฟล์ Excel หรือ PDF อย่างน้อย 1 ไฟล์';
            return;
        }
        if (files.length > 5) {
            statusBox.className = 'alert alert-warning mt-3 mb-0';
            statusBox.textContent = 'เลือกไฟล์ได้สูงสุดครั้งละ 5 ไฟล์';
            return;
        }
        const invalidFile = files.find(file => !/\.(xlsx|pdf)$/i.test(file.name));
        if (invalidFile) {
            statusBox.className = 'alert alert-warning mt-3 mb-0';
            statusBox.textContent = 'รองรับเฉพาะไฟล์ .xlsx และ .pdf เท่านั้น: ' + invalidFile.name;
            return;
        }

        const button = this;
        const originalText = button.textContent;
        const formData = new FormData();
        files.forEach(function (file) { formData.append('import_files[]', file); });
        formData.append('csrf_token', <?php echo json_encode((string)$_SESSION['csrf_token']); ?>);

        button.disabled = true;
        button.textContent = 'กำลังอ่าน ' + files.length + ' ไฟล์...';
        statusBox.className = 'alert alert-info mt-3 mb-0';
        statusBox.textContent = 'กำลังประมวลผลไฟล์ Excel / PDF กรุณารอสักครู่';
        importQueueBox.className = 'mt-3 d-none';
        importQueueBox.innerHTML = '';

        fetch('import_excel.php', { method: 'POST', body: formData })
            .then(function (response) {
                return response.text().then(function (text) {
                    let result;
                    try {
                        result = JSON.parse(text);
                    } catch (parseError) {
                        const plainText = String(text || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                        throw new Error(plainText || 'เซิร์ฟเวอร์ส่งข้อมูลกลับมาไม่ถูกต้อง กรุณาตรวจสอบ PHP Error Log');
                    }
                    if (!response.ok) {
                        throw new Error(result.message || ('HTTP Error ' + response.status));
                    }
                    return result;
                });
            })
            .then(function (result) {
                if (!result.success) throw new Error(result.message || 'ไม่สามารถอ่านข้อมูลจากไฟล์ได้');
                const results = Array.isArray(result.data && result.data.results) ? result.data.results : [];
                renderImportQueue(results);
                const successCount = results.filter(item => item.success).length;
                const failedCount = results.length - successCount;
                statusBox.className = 'alert alert-success mt-3 mb-0';
                statusBox.textContent = 'อ่านสำเร็จ ' + successCount + ' ไฟล์' + (failedCount ? ' / ไม่สำเร็จ ' + failedCount + ' ไฟล์' : '') + ' กรุณาเลือกตรวจสอบและบันทึกทีละรายการ';
            })
            .catch(function (error) {
                statusBox.className = 'alert alert-danger mt-3 mb-0';
                statusBox.textContent = error.message || 'เกิดข้อผิดพลาดในการอ่านไฟล์';
            })
            .finally(function () {
                button.disabled = false;
                button.textContent = originalText;
            });
    });
    document.getElementById('addItemRow').addEventListener('click', function(){ addRow(); });
    itemRows.addEventListener('input', function(e){ if(e.target.classList.contains('js-calc')) calculate(); });
    itemRows.addEventListener('click', function(e){ if(e.target.classList.contains('js-remove-row')) { e.target.closest('tr').remove(); if(!itemRows.children.length) addRow(); calculate(); } });

    document.querySelectorAll('.js-edit-quote').forEach(function(button){ button.addEventListener('click', function(){
        const quote = JSON.parse(button.dataset.quote || '{}');
        resetForm();
        document.getElementById('wcsFormTitle').textContent = 'แก้ไขใบเสนอราคางานซ่อม WCS';
        document.getElementById('quote_id').value = quote.id || '';
        document.getElementById('repair_job_no').value = quote.repair_job_no || '';
        document.getElementById('quote_date').value = quote.quote_date || '';
        document.getElementById('branch_name').value = quote.branch_name || '';
        document.getElementById('asset_code').value = quote.asset_code || '';
        document.getElementById('printer_model').value = quote.printer_model || '';
        document.getElementById('serial_number').value = quote.serial_number || '';
        document.getElementById('remark').value = quote.remark || '';
        setImportedAttachments(quote.attachments || []);
        itemRows.innerHTML = '';
        (quote.items || []).forEach(addRow);
        if (!(quote.items || []).length) addRow();
        calculate();
        formModal.show();
    }); });

    document.querySelectorAll('.js-view-quote').forEach(function(button){ button.addEventListener('click', function(){
        const quote = JSON.parse(button.dataset.quote || '{}');
        document.getElementById('detailSubtitle').textContent = quote.repair_job_no || '-';
        document.getElementById('detailJobNo').textContent = quote.repair_job_no || '-';
        document.getElementById('detailDate').textContent = quote.quote_date ? quote.quote_date.split('-').reverse().join('/') : '-';
        document.getElementById('detailBranch').textContent = quote.branch_name || '-';
        document.getElementById('detailAsset').textContent = quote.asset_code || '-';
        document.getElementById('detailPrinterModel').textContent = quote.printer_model || '-';
        document.getElementById('detailSerialNumber').textContent = quote.serial_number || '-';
        document.getElementById('detailRemark').textContent = quote.remark || '-';
        document.getElementById('detailSubtotal').textContent = money(quote.subtotal);
        document.getElementById('detailVat').textContent = money(quote.vat_amount);
        document.getElementById('detailTotal').textContent = money(quote.total_amount);
        document.getElementById('detailItems').innerHTML = (quote.items || []).map(item => { const vat = Number(item.line_amount || 0) * 0.07; return `<tr><td>${escapeHtml(item.product_code)}</td><td>${escapeHtml(item.repair_description)}</td><td class="text-end">${money(item.quantity)}</td><td class="text-end">${money(item.unit_price)}</td><td class="text-end">${money(vat)}</td><td class="text-end fw-bold">${money(Number(item.line_amount || 0) + vat)}</td></tr>`; }).join('');
        const attachments = Array.isArray(quote.attachments) ? quote.attachments : [];
        const detailAttachmentSection = document.getElementById('detailAttachmentSection');
        const detailAttachments = document.getElementById('detailAttachments');
        detailAttachments.innerHTML = attachments.map(function (attachment) {
            const url = attachmentUrl(attachment.file_path);
            return `<button type="button" class="wcs-detail-attachment js-view-wcs-image" data-image-url="${escapeHtml(url)}" data-sheet-name="${escapeHtml(attachment.sheet_name || 'เอกสารแนบ')}" data-file-name="${escapeHtml(attachment.file_name || '')}"><img src="${escapeHtml(url)}" alt="${escapeHtml(attachment.sheet_name || 'เอกสารแนบ')}"><span>${escapeHtml(attachment.sheet_name || 'เอกสารแนบ')}</span></button>`;
        }).join('');
        detailAttachmentSection.classList.toggle('d-none', attachments.length === 0);

        bootstrap.Modal.getOrCreateInstance(document.getElementById('wcsDetailModal')).show();
    }); });
    document.addEventListener('click', function (event) {
        const button = event.target.closest('.js-view-wcs-image');
        if (!button) {
            return;
        }
        const imageUrl = button.dataset.imageUrl || '';
        document.getElementById('imageViewerTitle').textContent = button.dataset.sheetName || 'รูปภาพเอกสาร';
        document.getElementById('imageViewerFileName').textContent = button.dataset.fileName || '-';
        document.getElementById('imageViewerImage').src = imageUrl;
        document.getElementById('imageViewerOpenLink').href = imageUrl;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('wcsImageViewerModal')).show();
    });

    resetForm();
});
</script>

<!-- HDD_GLOBAL_MODAL_LAYER_FIX_V2 -->
<style>
html body > .modal { position: fixed !important; z-index: 2147483000 !important; }
html body > .modal.show { display: block !important; }
html body > .modal-backdrop { position: fixed !important; z-index: 2147482990 !important; }
html body.modal-open { overflow: hidden !important; }
</style>
<script>
(function () {
    'use strict';
    function moveModalToBody(modal) {
        if (modal && modal.classList && modal.classList.contains('modal') && modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
    }
    function normalizeAllModals() { document.querySelectorAll('.modal').forEach(moveModalToBody); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', normalizeAllModals);
    } else {
        normalizeAllModals();
    }
    document.addEventListener('show.bs.modal', function (event) { moveModalToBody(event.target); }, true);
    document.addEventListener('shown.bs.modal', function (event) {
        moveModalToBody(event.target);
        if (event.target) event.target.style.zIndex = '2147483000';
        document.querySelectorAll('body > .modal-backdrop').forEach(function (backdrop) {
            backdrop.style.zIndex = '2147482990';
        });
    }, true);
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
