<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function getPdoConnection(): PDO
{
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) {
        return $GLOBALS['conn'];
    }

    if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
        return $GLOBALS['db'];
    }

    if (function_exists('getConnection')) {
        $connection = getConnection();
        if ($connection instanceof PDO) {
            return $connection;
        }
    }

    throw new Exception('ไม่พบการเชื่อมต่อฐานข้อมูล PDO');
}

function formatMainBranchCode($value): string
{
    $value = trim((string)($value ?? ''));

    if ($value === '') {
        return '-';
    }

    if (is_numeric($value)) {
        $value = (string)(int)$value;
    }

    if (ctype_digit($value) && strlen($value) < 3) {
        return str_pad($value, 3, '0', STR_PAD_LEFT);
    }

    return $value;
}

function formatThaiDateTime($value): string
{
    $value = trim((string)($value ?? ''));

    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return date('d/m/Y H:i');
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return date('d/m/Y H:i');
    }

    return date('d/m/Y H:i', $timestamp);
}

$errorMessage = '';
$data = null;

try {
    $pdo = getPdoConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $requestId = (int)($_GET['request_id'] ?? 0);

    if ($requestId <= 0) {
        throw new Exception('ไม่พบเลขอ้างอิงคำขอ');
    }

    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.request_no,
            r.main_branch_code,
            r.branch_code,
            r.branch_name,
            r.hdd_serial,
            r.request_reason,
            r.status,
            r.remark,
            r.created_at,

            b.full_address,
            b.phone,
            b.landmark
        FROM harddisk_delivery_requests r
        LEFT JOIN branch_directory b
            ON b.branch_code = r.branch_code
        WHERE r.id = :request_id
        LIMIT 1
    ");

    $stmt->execute([
        ':request_id' => $requestId
    ]);

    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        throw new Exception('ไม่พบข้อมูลคำขอส่ง HDD');
    }
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

$senderName = 'บริษัทเมืองไทยแคปปิตอล จำกัด(มหาชน)';
$senderAddress1 = '332/1 ถนนจรัญสนิทวงศ์ แขวงบางพลัด';
$senderAddress2 = 'เขตบางพลัด กรุงเทพมหานคร 10700';
$senderPhone = '02-483-8888, 061-271-3113';

$receiverMainBranchCode = $data ? formatMainBranchCode($data['main_branch_code'] ?? '') : '-';
$receiverCostCenter = $data['branch_code'] ?? '-';
$receiverBranchName = $data['branch_name'] ?? '-';
$receiverAddress = $data['full_address'] ?? '';
$receiverPhone = $data['phone'] ?? '';
$receiverLandmark = $data['landmark'] ?? '';
$requestNo = $data['request_no'] ?? '-';
$hddSerial = $data['hdd_serial'] ?? '';
$createdAt = $data['created_at'] ?? '';
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>ใบแปะหน้ากล่องพัสดุ HDD</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        * {
            box-sizing: border-box;
        }

        :root {
            --label-font: "Leelawadee UI", Tahoma, "Segoe UI", Arial, sans-serif;
        }

        .label-paper,
        .alert-error {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        html,
        body,
        button,
        input,
        textarea,
        select {
            font-family: var(--label-font);
        }

        body {
            margin: 0;
            background: #e5e7eb;
            color: #111827;
            font-size: 16px;
            line-height: 1.35;
        }

        .page-wrap {
            width: 100%;
            max-width: 1120px;
            margin: 18px auto;
            padding: 0 12px;
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .toolbar-title {
            font-size: 24px;
            font-weight: 700;
            line-height: 1.1;
        }

        .toolbar-subtitle {
            font-size: 15px;
            color: #475569;
        }

        .toolbar-actions {
            display: flex;
            gap: 8px;
        }

        .btn {
            border: 0;
            border-radius: 8px;
            padding: 9px 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 16px;
            line-height: 1.25;
        }

        .btn-primary {
            background: #2563eb;
            color: #ffffff;
        }

        .btn-secondary {
            background: #6b7280;
            color: #ffffff;
        }

        .alert-error {
            width: 210mm;
            min-height: 148mm;
            margin: 0 auto;
            background: #fee2e2;
            border: 2px solid #ef4444;
            color: #991b1b;
            padding: 18px;
            border-radius: 12px;
            font-size: 20px;
        }

        .label-paper {
            width: 210mm;
            height: 148mm;
            margin: 0 auto;
            background: #ffffff;
            border: 2px solid #111827;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.18);
            display: flex;
            flex-direction: column;
        }

        .label-header {
            height: 23mm;
            background: #0f172a;
            color: #ffffff;
            padding: 4mm 6mm;
            display: grid;
            grid-template-columns: 1fr 67mm;
            gap: 6mm;
            align-items: center;
        }

        .label-title {
            font-size: 26px;
            font-weight: 800;
            letter-spacing: 0.2px;
            line-height: 1;
        }

        .label-subtitle {
            font-size: 14px;
            opacity: 0.9;
            margin-top: 2px;
            line-height: 1;
        }

        .request-box {
            text-align: right;
            font-size: 14px;
            line-height: 1.2;
        }

        .request-no {
            font-size: 22px;
            font-weight: 800;
            color: #fde68a;
            line-height: 1;
            margin: 1mm 0;
        }

        .label-body {
            flex: 1;
            padding: 5mm 6mm 4mm 6mm;
            display: flex;
            flex-direction: column;
            gap: 4mm;
        }

        .top-info-row {
            display: grid;
            grid-template-columns: 34mm 48mm 1fr;
            gap: 4mm;
        }

        .info-card {
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 2.5mm 3mm;
            background: #f8fafc;
            min-height: 19mm;
        }

        .info-label {
            font-size: 14px;
            color: #64748b;
            font-weight: 600;
            line-height: 1;
            margin-bottom: 1mm;
        }

        .info-value {
            font-size: 24px;
            font-weight: 900;
            color: #111827;
            line-height: 1;
            word-break: break-word;
        }

        .info-value.blue {
            color: #1d4ed8;
        }

        .info-value.green {
            color: #047857;
        }

        .barcode-text {
            font-family: Consolas, Monaco, monospace;
            font-size: 20px;
            font-weight: 800;
            color: #7c2d12;
            line-height: 1;
        }

        .address-grid {
            display: grid;
            grid-template-columns: 0.88fr 1.42fr;
            gap: 4mm;
            flex: 1;
            min-height: 0;
        }

        .section {
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .section-title {
            padding: 2mm 4mm;
            font-size: 19px;
            font-weight: 800;
            border-bottom: 1px solid #cbd5e1;
            line-height: 1;
        }

        .section-title.sender {
            background: #f1f5f9;
            color: #334155;
        }

        .section-title.receiver {
            background: #dbeafe;
            color: #1e3a8a;
        }

        .section-content {
            padding: 3mm 4mm;
            flex: 1;
            min-height: 0;
        }

        .sender-name {
            font-size: 19px;
            font-weight: 800;
            margin-bottom: 2mm;
            line-height: 1;
        }

        .receiver-name {
            font-size: 24px;
            font-weight: 900;
            color: #1d4ed8;
            margin-bottom: 2mm;
            line-height: 1.05;
            overflow-wrap: anywhere;
        }

        .address {
            font-size: 19px;
            line-height: 1.12;
            margin-bottom: 2mm;
            font-weight: 500;
            overflow-wrap: anywhere;
        }

        .sender-section .address {
            font-size: 16px;
            line-height: 1.12;
        }

        .phone {
            font-size: 18px;
            font-weight: 800;
            line-height: 1.2;
            color: #111827;
        }

        .landmark-box {
            margin-top: 2.5mm;
            padding: 2mm 3mm;
            border-radius: 9px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            font-size: 16px;
            line-height: 1.25;
            overflow-wrap: anywhere;
        }

        .landmark-label {
            font-weight: 800;
            color: #92400e;
            margin-bottom: 1mm;
        }

        .footer-note {
            border-top: 1px dashed #94a3b8;
            padding-top: 2mm;
            display: flex;
            justify-content: space-between;
            gap: 4mm;
            font-size: 13px;
            line-height: 1.25;
            color: #475569;
        }

        .text-danger {
            color: #dc2626;
            font-weight: 800;
        }


        .warning-banner {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4mm;
        }

        .warning-box {
            border: 2px dashed #dc2626;
            border-radius: 12px;
            background: linear-gradient(135deg, #fff7ed 0%, #fef2f2 100%);
            padding: 3mm 4mm;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 3mm;
            min-height: 16mm;
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.7);
        }

        .warning-icon {
            font-size: 28px;
            line-height: 1;
            flex-shrink: 0;
        }

        .warning-text-wrap {
            text-align: center;
        }

        .warning-title {
            font-size: 26px;
            font-weight: 900;
            line-height: 1;
            color: #b91c1c;
            letter-spacing: 0.3px;
        }

        .warning-subtitle {
            font-size: 14px;
            font-weight: 700;
            color: #92400e;
            margin-top: 1mm;
            line-height: 1.15;
        }

        .receiver-section {
            position: relative;
        }

        .fragile-sticker {
            position: absolute;
            top: 3mm;
            right: 4mm;
            padding: 1.5mm 2.5mm;
            border-radius: 999px;
            background: #dc2626;
            color: #ffffff;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.2px;
            box-shadow: 0 4px 10px rgba(220, 38, 38, 0.25);
        }


        @media print {
            html,
            body {
                width: 210mm;
                height: 148mm;
                margin: 0;
                padding: 0;
                background: #ffffff;
                font-family: var(--label-font);
                font-size: 16px;
            }

            .page-wrap {
                width: 100%;
                max-width: none;
                margin: 0;
                padding: 0;
            }

            .toolbar {
                display: none;
            }

            .label-paper {
                width: 100%;
                height: 100%;
                margin: 0;
                border-radius: 0;
                box-shadow: none;
                border: 2px solid #000000;
                page-break-inside: avoid;
            }

            .alert-error {
                width: 100%;
                height: 100%;
                border-radius: 0;
                margin: 0;
                box-shadow: none;
            }

            @page {
                size: A5 landscape;
                margin: 0;
            }
        }
    </style>
</head>

<body>
<div class="page-wrap">

    <div class="toolbar">
        <div>
            <div class="toolbar-title">ใบแปะหน้ากล่องพัสดุ HDD</div>
            <div class="toolbar-subtitle">ขนาด A5 แนวนอน</div>
        </div>

        <div class="toolbar-actions">
            <button type="button" class="btn btn-secondary" onclick="closePrintLabelPage();">ปิดหน้า</button>
            <button type="button" class="btn btn-primary" onclick="window.print();">
                ปริ้นใบแปะหน้ากล่อง
            </button>
        </div>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert-error">
            <?php echo h($errorMessage); ?>
        </div>
    <?php else: ?>

        <div class="label-paper">

            <div class="label-header">
                <div>
                    <div class="label-title">ใบแปะหน้ากล่องพัสดุ HDD</div>
                    <div class="label-subtitle">Harddisk Delivery Request</div>
                </div>

                <div class="request-box">
                    <div>เลขที่คำขอ</div>
                    <div class="request-no"><?php echo h($requestNo); ?></div>
                    <div>วันที่: <?php echo h(formatThaiDateTime($createdAt)); ?></div>
                </div>
            </div>

            <div class="label-body">

                <div class="top-info-row">
                    <div class="info-card">
                        <div class="info-label">รหัสสาขา</div>
                        <div class="info-value blue"><?php echo h($receiverMainBranchCode); ?></div>
                    </div>

                    <div class="info-card">
                        <div class="info-label">Cost Center</div>
                        <div class="info-value green"><?php echo h($receiverCostCenter); ?></div>
                    </div>

                    <div class="info-card">
                        <div class="info-label">Serial HDD</div>
                        <div class="info-value">
                            <?php if (trim((string)$hddSerial) !== ''): ?>
                                <span class="barcode-text"><?php echo h($hddSerial); ?></span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </div>
                    </div>
                </div>



                <div class="warning-banner">
                    <div class="warning-box">
                        <div class="warning-icon">📦</div>
                        <div class="warning-text-wrap">
                            <div class="warning-title">ห้ามโยน</div>
                            <div class="warning-subtitle">กรุณาจัดวางและขนย้ายอย่างระมัดระวัง</div>
                        </div>
                    </div>

                    <div class="warning-box">
                        <div class="warning-icon">⚠️</div>
                        <div class="warning-text-wrap">
                            <div class="warning-title">ระวังแตก</div>
                            <div class="warning-subtitle">ภายในบรรจุอุปกรณ์อิเล็กทรอนิกส์</div>
                        </div>
                    </div>
                </div>

                <div class="address-grid">

                    <div class="section sender-section">
                        <div class="section-title sender">ผู้จัดส่ง</div>
                        <div class="section-content">
                            <div class="sender-name"><?php echo h($senderName); ?></div>

                            <div class="address">
                                <?php echo h($senderAddress1); ?><br>
                                <?php echo h($senderAddress2); ?>
                            </div>

                            <div class="phone">
                                โทร <?php echo h($senderPhone); ?>
                            </div>
                        </div>
                    </div>

                    <div class="section receiver-section">
                        <div class="section-title receiver">ผู้รับ / สาขาปลายทาง</div>
                        <div class="fragile-sticker">ห้ามโยน • ระวังแตก</div>
                        <div class="section-content">
                            <div class="receiver-name">
                                สาขา <?php echo h($receiverBranchName); ?>
                            </div>

                            <div class="address">
                                <?php if (trim((string)$receiverAddress) !== ''): ?>
                                    <?php echo nl2br(h($receiverAddress)); ?>
                                <?php else: ?>
                                    <span class="text-danger">
                                        ไม่พบข้อมูลที่อยู่สาขาใน branch_directory.full_address
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="phone">
                                โทรสาขา:
                                <?php echo trim((string)$receiverPhone) !== '' ? h($receiverPhone) : '-'; ?>
                            </div>

                            <?php if (trim((string)$receiverLandmark) !== ''): ?>
                                <div class="landmark-box">
                                    <div class="landmark-label">จุดสังเกต / Landmark</div>
                                    <div><?php echo nl2br(h($receiverLandmark)); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

                <div class="footer-note">
                    <div>
                        <strong>หมายเหตุ:</strong>
                        กรุณาตรวจสอบชื่อสาขาและ Cost Center ให้ถูกต้องก่อนจัดส่ง
                    </div>

                    <div>
                        Harddisk Delivery Web
                    </div>
                </div>

            </div>
        </div>

    <?php endif; ?>

</div>

<script>
function closePrintLabelPage() {
    window.open('', '_self');
    window.close();

    setTimeout(function () {
        if (!window.closed) {
            alert('หากเบราว์เซอร์ไม่อนุญาตให้ปิดแท็บอัตโนมัติ กรุณากดปิดแท็บนี้เอง');
        }
    }, 300);
}
</script>
</body>
</html>