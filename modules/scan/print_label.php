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

        html,
        body,
        button,
        input,
        textarea,
        select {
            font-family: "TH Sarabun New", Tahoma, Arial, sans-serif;
        }

        body {
            margin: 0;
            background: #e5e7eb;
            color: #111827;
            font-size: 20px;
            line-height: 1.2;
        }

        .page-wrap {
            max-width: 1100px;
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
            font-size: 28px;
            font-weight: 700;
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
            font-size: 22px;
            line-height: 1.1;
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
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 14px;
            border-radius: 12px;
            font-size: 24px;
        }

        .label-paper {
            width: 210mm;
            min-height: 148mm;
            margin: 0 auto;
            background: #ffffff;
            border: 2px solid #111827;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.18);
        }

        .label-header {
            background: #0f172a;
            color: #ffffff;
            padding: 9mm 9mm 6mm 9mm;
            display: grid;
            grid-template-columns: 1fr 62mm;
            gap: 8mm;
            align-items: center;
        }

        .label-title {
            font-size: 34px;
            font-weight: 800;
            letter-spacing: 0.2px;
            line-height: 1.05;
        }

        .label-subtitle {
            font-size: 22px;
            opacity: 0.9;
            margin-top: 2px;
            line-height: 1.1;
        }

        .request-box {
            text-align: right;
            font-size: 20px;
            line-height: 1.1;
        }

        .request-no {
            font-size: 30px;
            font-weight: 800;
            color: #fde68a;
            line-height: 1.05;
        }

        .label-body {
            padding: 6mm 8mm 6mm 8mm;
        }

        .highlight-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 4mm;
            margin-bottom: 5mm;
        }

        .highlight-card {
            border: 1px solid #d1d5db;
            border-radius: 10px;
            padding: 3mm 4mm;
            background: #f9fafb;
        }

        .highlight-label {
            font-size: 20px;
            color: #6b7280;
            margin-bottom: 1mm;
            font-weight: 500;
            line-height: 1.05;
        }

        .highlight-value {
            font-size: 32px;
            font-weight: 900;
            color: #111827;
            line-height: 1;
        }

        .highlight-value.blue {
            color: #1d4ed8;
        }

        .highlight-value.green {
            color: #047857;
        }

        .barcode-text {
            font-family: Consolas, Monaco, monospace;
            font-size: 22px;
            font-weight: 800;
            color: #7c2d12;
        }

        .address-grid {
            display: grid;
            grid-template-columns: 1fr 1.35fr;
            gap: 5mm;
            align-items: stretch;
        }

        .section {
            border: 1px solid #d1d5db;
            border-radius: 12px;
            overflow: hidden;
            min-height: 61mm;
        }

        .section-title {
            padding: 2.5mm 4mm;
            font-size: 25px;
            font-weight: 800;
            border-bottom: 1px solid #d1d5db;
            line-height: 1;
        }

        .section-title.sender {
            background: #f3f4f6;
            color: #374151;
        }

        .section-title.receiver {
            background: #dbeafe;
            color: #1e3a8a;
        }

        .section-content {
            padding: 4mm;
        }

        .sender-name {
            font-size: 27px;
            font-weight: 800;
            margin-bottom: 2mm;
            line-height: 1.05;
        }

        .receiver-name {
            font-size: 31px;
            font-weight: 900;
            color: #1d4ed8;
            margin-bottom: 2mm;
            line-height: 1.05;
        }

        .address {
            font-size: 25px;
            line-height: 1.15;
            margin-bottom: 2mm;
            font-weight: 500;
        }

        .sender-section .address {
            font-size: 23px;
            line-height: 1.15;
        }

        .phone {
            font-size: 24px;
            font-weight: 700;
            line-height: 1.12;
        }

        .landmark-box {
            margin-top: 3mm;
            padding: 2.5mm 3mm;
            border-radius: 10px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            font-size: 22px;
            line-height: 1.12;
        }

        .landmark-label {
            font-weight: 800;
            color: #92400e;
            margin-bottom: 1mm;
        }

        .footer-note {
            border-top: 1px dashed #9ca3af;
            padding-top: 3mm;
            margin-top: 4mm;
            display: flex;
            justify-content: space-between;
            gap: 6mm;
            font-size: 18px;
            line-height: 1.1;
            color: #4b5563;
        }

        @media print {
            body {
                background: #ffffff;
                font-family: "TH Sarabun New", Tahoma, Arial, sans-serif;
                font-size: 20px;
            }

            .page-wrap {
                max-width: none;
                width: 100%;
                margin: 0;
                padding: 0;
            }

            .toolbar {
                display: none;
            }

            .label-paper {
                width: 100%;
                min-height: auto;
                height: 136mm;
                box-shadow: none;
                border-radius: 0;
                border: 2px solid #000000;
                page-break-inside: avoid;
            }

            .label-header {
                padding: 6mm 7mm 4mm 7mm;
            }

            .label-body {
                padding: 5mm 7mm 5mm 7mm;
            }

            .section {
                min-height: 58mm;
            }

            @page {
                size: A5 landscape;
                margin: 6mm;
            }
        }
    </style>
</head>

<body>
<div class="page-wrap">

    <div class="toolbar">
        <div>
            <div class="toolbar-title">ใบแปะหน้ากล่องพัสดุ HDD</div>
            <div>ขนาดกระดาษ A5 แนวนอน</div>
        </div>

        <div class="toolbar-actions">
            <a href="javascript:history.back();" class="btn btn-secondary">ย้อนกลับ</a>
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

                <div class="highlight-row">
                    <div class="highlight-card">
                        <div class="highlight-label">รหัสสาขาใหญ่</div>
                        <div class="highlight-value blue"><?php echo h($receiverMainBranchCode); ?></div>
                    </div>

                    <div class="highlight-card">
                        <div class="highlight-label">Cost Center</div>
                        <div class="highlight-value green"><?php echo h($receiverCostCenter); ?></div>
                    </div>

                    <div class="highlight-card">
                        <div class="highlight-label">Serial HDD</div>
                        <div class="highlight-value">
                            <?php if ($hddSerial !== ''): ?>
                                <span class="barcode-text"><?php echo h($hddSerial); ?></span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
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
                        <div class="section-content">
                            <div class="receiver-name">
                                สาขา <?php echo h($receiverBranchName); ?>
                            </div>

                            <div class="address">
                                <?php if (trim((string)$receiverAddress) !== ''): ?>
                                    <?php echo nl2br(h($receiverAddress)); ?>
                                <?php else: ?>
                                    <span style="color:#dc2626;font-weight:700;">
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
                        เอกสารนี้สร้างจากระบบ Harddisk Delivery Web
                    </div>
                </div>

            </div>
        </div>

    <?php endif; ?>

</div>
</body>
</html>