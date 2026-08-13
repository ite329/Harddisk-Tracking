<?php

require_once __DIR__ . '/../../includes/auth.php';

date_default_timezone_set('Asia/Bangkok');
$printedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('d/m/Y H:i');

require_login();
require_permission('branch_label.view');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}
function pe($v): string { return htmlspecialchars(trim((string)($v ?? '')), ENT_QUOTES, 'UTF-8'); }
if (empty($_SESSION['csrf_branch_label_print'])) {
    $_SESSION['csrf_branch_label_print'] = bin2hex(random_bytes(32));
}
$rawBranchName = trim((string)($_POST['branch_name'] ?? '-'));
$rawMainCode = trim((string)($_POST['main_code'] ?? '-'));
$rawMainBranchName = trim((string)($_POST['main_branch_name'] ?? ''));
$rawBranchCode = trim((string)($_POST['branch_code'] ?? '-'));
$rawAddress = trim((string)($_POST['address'] ?? '-'));
$rawAssetName = trim((string)($_POST['asset_name'] ?? ''));
$printSource = (($_POST['print_source'] ?? 'direct_branch') === 'main_branch_group') ? 'main_branch_group' : 'direct_branch';
$branchName = pe($rawBranchName);
$mainCode = pe($rawMainCode);
$mainBranchName = pe($rawMainBranchName);
$branchCode = pe($rawBranchCode);
$address = pe($rawAddress);
$phone = pe($_POST['phone'] ?? '');
$landmark = pe($_POST['landmark'] ?? '');
$assetName = pe($rawAssetName);
$assetImage = pe($_POST['asset_image'] ?? '');
$autoPrint = false; // ปิดการเปิด Print Preview อัตโนมัติ
$printOrientation = (($_POST['print_orientation'] ?? 'portrait') === 'landscape') ? 'landscape' : 'portrait';
$base = '/harddisk_delivery_web';
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ใบปะหน้าพัสดุ - <?php echo $branchName; ?></title>
<link rel="preload" href="<?php echo $base; ?>/assets/fonts/sarabun/Sarabun-Regular.ttf?v=2" as="font" type="font/ttf" crossorigin>
<link rel="preload" href="<?php echo $base; ?>/assets/fonts/sarabun/Sarabun-Bold.ttf?v=2" as="font" type="font/ttf" crossorigin>
<style id="pageOrientationStyle">@page{size:A4 <?php echo $printOrientation; ?>;margin:8mm}</style>
<style>
@font-face{font-family:"SarabunLocal";src:url("<?php echo $base; ?>/assets/fonts/sarabun/Sarabun-Regular.ttf?v=2") format("truetype");font-style:normal;font-weight:400;font-display:block}
@font-face{font-family:"SarabunLocal";src:url("<?php echo $base; ?>/assets/fonts/sarabun/Sarabun-SemiBold.ttf?v=2") format("truetype");font-style:normal;font-weight:600;font-display:block}
@font-face{font-family:"SarabunLocal";src:url("<?php echo $base; ?>/assets/fonts/sarabun/Sarabun-Bold.ttf?v=2") format("truetype");font-style:normal;font-weight:700;font-display:block}
@font-face{font-family:"SarabunLocal";src:url("<?php echo $base; ?>/assets/fonts/sarabun/Sarabun-ExtraBold.ttf?v=2") format("truetype");font-style:normal;font-weight:800;font-display:block}
@font-face{font-family:"SarabunLocal";src:url("<?php echo $base; ?>/assets/fonts/sarabun/Sarabun-ExtraBold.ttf?v=2") format("truetype");font-style:normal;font-weight:900;font-display:block}
*{box-sizing:border-box}
:root{--blue:#0f4c81;--blue2:#1769aa;--ink:#0f172a;--muted:#64748b;--line:#cbd5e1;--soft:#f8fafc;--accent:#00acc1}
html,body,body *,button,input,select,textarea{font-family:"SarabunLocal",sans-serif!important}
body{margin:0;background:#e9eef5;color:var(--ink)}
.toolbar{position:sticky;top:0;z-index:10;display:flex;justify-content:center;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 16px;background:linear-gradient(135deg,var(--blue),var(--blue2));box-shadow:0 6px 20px rgba(15,76,129,.25)}
.toolbar button{border:0;border-radius:9px;padding:9px 17px;font-weight:800;cursor:pointer;font-size:14px;transition:.18s ease}
.toolbar button:hover{transform:translateY(-1px)}
.print-btn{background:#fff;color:var(--blue)}
.close-btn{background:#dbe5ee;color:#334155}
.orientation-group{display:inline-flex;gap:5px;padding:4px;border-radius:10px;background:rgba(255,255,255,.14)}
.orientation-btn{background:transparent!important;color:#fff;border:1px solid rgba(255,255,255,.45)!important;padding:7px 13px!important}
.orientation-btn.active{background:#fff!important;color:var(--blue)!important;border-color:#fff!important}
.sheet{width:210mm;min-height:297mm;margin:18px auto;background:#fff;padding:18mm 12mm;display:flex;justify-content:center;align-items:flex-start;box-shadow:0 10px 35px rgba(15,23,42,.18)}
.parcel-label{position:relative;width:186mm;min-height:125mm;border:2.4px solid #0f172a;border-radius:4mm;overflow:hidden;background:#fff;box-shadow:inset 0 0 0 1px #fff}
.label-header{display:flex;align-items:stretch;justify-content:space-between;background:linear-gradient(135deg,#0f4c81,#1769aa);color:#fff;border-bottom:2px solid #0f172a}
.label-header-main{padding:4mm 5mm;min-width:0}
.label-eyebrow{font-size:8pt;font-weight:800;letter-spacing:.5px;opacity:.88}
.label-title{font-size:17pt;font-weight:900;line-height:1.1;margin-top:1mm}
.label-header-code{display:flex;align-items:center;justify-content:center;min-width:43mm;padding:3mm;border-left:1px solid rgba(255,255,255,.3);text-align:center}
.label-header-code strong{display:block;font-size:16pt;line-height:1.1}.label-header-code span{display:block;font-size:7.5pt;opacity:.85;margin-top:1mm}
.label-body{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(54mm,.75fr);min-height:95mm}
.label-main{padding:5mm 6mm;border-right:1.5px solid #0f172a}
.label-side{padding:5mm;background:#f8fafc;display:flex;flex-direction:column;gap:3mm}
.section-label{display:flex;align-items:center;gap:2mm;font-size:8.5pt;font-weight:900;color:var(--blue);text-transform:uppercase;letter-spacing:.25px;margin-bottom:1.5mm}
.section-label::before{content:"";width:3mm;height:3mm;border-radius:50%;background:var(--accent);flex:0 0 3mm}
.sender-box{padding:3mm 3.5mm;border:1px solid var(--line);border-radius:2.5mm;background:#f8fafc;font-size:8.7pt;line-height:1.45}
.recipient-block{margin-top:3mm;padding-top:3mm;border-top:1.5px dashed #94a3b8}
.recipient-name{font-size:16pt;font-weight:900;line-height:1.25;color:#0f172a;margin-bottom:2mm}
.code-row{display:flex;gap:2mm;flex-wrap:wrap;margin-bottom:2.5mm}
.code-pill{display:inline-flex;align-items:center;gap:1.5mm;border:1.5px solid #0f4c81;border-radius:2mm;padding:1.5mm 2.5mm;background:#eff6ff;color:#0f4c81;font-size:9pt;font-weight:900}
.address-box{font-size:10pt;line-height:1.5;color:#111827}
.address-line{margin-top:1.3mm}.address-line strong{color:#334155}
.asset-card{border:1px solid #cbd5e1;border-radius:3mm;background:#fff;overflow:hidden}
.asset-card-title{padding:2.2mm 3mm;background:#eaf3fb;color:#0f4c81;font-size:8pt;font-weight:900;border-bottom:1px solid #cbd5e1}
.asset-card-name{padding:2.5mm 3mm 1.5mm;font-size:10pt;font-weight:900;text-align:center;line-height:1.3}
.selected-asset-image{display:block;width:100%;height:34mm;object-fit:contain;padding:2mm;background:#fff}
.notice-card{border:1.5px solid #f59e0b;border-radius:3mm;background:#fffbeb;padding:3mm;text-align:center;color:#92400e}
.notice-card strong{display:block;font-size:10pt}.notice-card span{display:block;font-size:7.5pt;margin-top:1mm;line-height:1.35}
.logo-row{margin-top:auto;display:grid;grid-template-columns:1fr 1fr;gap:3mm;align-items:center}
.logo-box{height:20mm;border:1px solid #dbe5ee;border-radius:2.5mm;background:#fff;display:flex;align-items:center;justify-content:center;padding:2mm}
.label-fragile-image,.label-courier-image{max-width:100%;max-height:100%;object-fit:contain}
.label-footer{display:flex;justify-content:space-between;align-items:center;gap:4mm;padding:2.5mm 5mm;border-top:1.5px solid #0f172a;background:#fff;font-size:7.5pt;color:#475569}
.label-footer strong{color:#0f172a}
body.landscape .sheet{width:297mm;min-height:210mm;padding:8mm;align-items:stretch}
body.landscape .parcel-label{width:281mm;min-height:194mm;display:flex;flex-direction:column}
body.landscape .label-header-main{padding:5mm 7mm}
body.landscape .label-eyebrow{font-size:10pt}
body.landscape .label-title{font-size:22pt}
body.landscape .label-header-code{min-width:54mm;padding:4mm}
body.landscape .label-header-code strong{font-size:22pt}
body.landscape .label-header-code span{font-size:9pt}
body.landscape .label-body{flex:1;grid-template-columns:minmax(0,1.85fr) minmax(72mm,.65fr);min-height:155mm}
body.landscape .label-main{padding:7mm 8mm}
body.landscape .label-side{padding:6mm;gap:4mm}
body.landscape .section-label{font-size:10.5pt;margin-bottom:2mm}
body.landscape .sender-box{padding:4mm 5mm;font-size:11pt;line-height:1.55}
body.landscape .recipient-block{margin-top:5mm;padding-top:5mm}
body.landscape .recipient-name{font-size:22pt;margin-bottom:3mm}
body.landscape .code-row{gap:3mm;margin-bottom:4mm}
body.landscape .code-pill{padding:2mm 3.5mm;font-size:11pt}
body.landscape .address-box{font-size:13pt;line-height:1.65}
body.landscape .asset-card-title{padding:3mm 4mm;font-size:10pt}
body.landscape .asset-card-name{padding:4mm;font-size:13pt;line-height:1.45}
body.landscape .selected-asset-image{height:48mm}
body.landscape .notice-card{padding:4mm}
body.landscape .notice-card strong{font-size:13pt}
body.landscape .notice-card span{font-size:9pt}
body.landscape .logo-box{height:28mm}
body.landscape .label-footer{padding:3.5mm 6mm;font-size:9pt}
@media(max-width:900px){.sheet{margin:0;transform-origin:top left}.toolbar{position:relative}.parcel-label{max-width:100%}}
@media print{
 body{background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}
 .toolbar{display:none!important}
 .sheet{width:100%;min-height:0;margin:0;padding:4mm 0;box-shadow:none;display:block}
 .parcel-label{margin:0 auto;break-inside:avoid;page-break-inside:avoid}
 body.landscape .sheet{width:100%;min-height:194mm;padding:0;align-items:stretch}
 body.landscape .parcel-label{width:100%;min-height:194mm;margin:0;display:flex;flex-direction:column}
 body.landscape .label-body{flex:1;min-height:0}
}
</style>
    <link href="/harddisk_delivery_web/assets/css/hdd-sarabun-font.css?v=20260727" rel="stylesheet">
</head><body class="<?php echo $printOrientation === 'landscape' ? 'landscape' : 'portrait'; ?>">
<div class="toolbar">
<div class="orientation-group" aria-label="เลือกแนวกระดาษ">
<button type="button" id="portraitBtn" class="orientation-btn<?php echo $printOrientation === 'portrait' ? ' active' : ''; ?>" onclick="setPrintOrientation('portrait')">แนวตั้ง</button>
<button type="button" id="landscapeBtn" class="orientation-btn<?php echo $printOrientation === 'landscape' ? ' active' : ''; ?>" onclick="setPrintOrientation('landscape')">แนวนอน</button>
</div>
<button class="print-btn" onclick="printLabel()">พิมพ์ใบปะหน้า</button><button class="close-btn" onclick="window.close()">ปิดหน้านี้</button></div>
<div class="sheet">
    <div class="parcel-label">
        <div class="label-header">
            <div class="label-header-main">
                <div class="label-eyebrow">MUANGTHAI CAPITAL PUBLIC COMPANY LIMITED</div>
                <div class="label-title">ใบปะหน้าพัสดุ / ที่อยู่สาขา</div>
            </div>
            <div class="label-header-code">
                <div><strong><?php echo $mainCode; ?></strong><span>รหัสสาขาใหญ่</span></div>
            </div>
        </div>

        <div class="label-body">
            <div class="label-main">
                <div class="section-label">ข้อมูลผู้ส่ง</div>
                <div class="sender-box">
                    <strong>บริษัท เมืองไทย แคปปิตอล จำกัด (มหาชน) (สำนักงานใหญ่)</strong><br>
                    332/1 ถนนจรัญสนิทวงศ์ แขวงบางพลัด เขตบางพลัด กรุงเทพมหานคร 10700<br>
                    โทร. 02-483-8888, 061-271-3113
                </div>

                <div class="recipient-block">
                    <div class="section-label">ข้อมูลผู้รับ</div>
                    <div class="recipient-name">ถึง : <?php echo $branchName; ?></div>
                    <div class="code-row">
                        <span class="code-pill">สาขาใหญ่ : <?php echo $mainBranchName !== '' ? $mainBranchName : $mainCode; ?></span>
                        <span class="code-pill">Cost Center : <?php echo $branchCode; ?></span>
                    </div>
                    <div class="address-box">
                        <div class="address-line"><strong>ที่อยู่ :</strong> <?php echo $address; ?></div>
                        <?php if ($phone !== ''): ?><div class="address-line"><strong>โทร :</strong> <?php echo $phone; ?></div><?php endif; ?>
                        <?php if ($landmark !== ''): ?><div class="address-line"><strong>จุดสังเกต :</strong> <?php echo $landmark; ?></div><?php endif; ?>
                    </div>
                </div>
            </div>

            <aside class="label-side">
                <div class="asset-card">
                    <div class="asset-card-title">รายการจัดส่ง</div>
                    <div class="asset-card-name"><?php echo $assetName !== '' ? $assetName : 'ไม่ระบุ'; ?></div>
                    <?php if ($assetImage !== ''): ?><img class="selected-asset-image" src="<?php echo $assetImage; ?>" alt="<?php echo $assetName; ?>"><?php endif; ?>
                </div>
                <div class="notice-card"><strong>โปรดระวังสินค้าแตกหัก</strong><span>กรุณาจัดวางและขนส่งด้วยความระมัดระวัง</span></div>
                <div class="logo-row">
                    <div class="logo-box"><img class="label-fragile-image" src="<?php echo $base; ?>/images/FRAGILE.jpg" alt="Fragile"></div>
                    <div class="logo-box"><img class="label-courier-image" src="<?php echo $base; ?>/images/Kerry-Express-Logo.png" alt="Kerry Express"></div>
                </div>
            </aside>
        </div>

        <div class="label-footer">
            <span><strong>เอกสารภายในองค์กร</strong> กรุณาตรวจสอบชื่อสาขาและ Cost Center ก่อนจัดส่ง</span>
            <span>พิมพ์เมื่อ <?php echo pe($printedAt); ?> น.</span>
        </div>
    </div>
</div>
<script>
function printLabel() {
    var historyPayload = {
        csrf_token: <?php echo json_encode($_SESSION['csrf_branch_label_print'], JSON_UNESCAPED_UNICODE); ?>,
        main_branch_code: <?php echo json_encode($rawMainCode, JSON_UNESCAPED_UNICODE); ?>,
        branch_code: <?php echo json_encode($rawBranchCode, JSON_UNESCAPED_UNICODE); ?>,
        branch_name: <?php echo json_encode($rawBranchName, JSON_UNESCAPED_UNICODE); ?>,
        shipping_address: <?php echo json_encode($rawAddress, JSON_UNESCAPED_UNICODE); ?>,
        asset_name: <?php echo json_encode($rawAssetName, JSON_UNESCAPED_UNICODE); ?>,
        print_orientation: document.body.classList.contains('landscape') ? 'landscape' : 'portrait',
        print_source: <?php echo json_encode($printSource, JSON_UNESCAPED_UNICODE); ?>
    };

    function openBrowserPrint() {
        if (!document.fonts) {
            window.print();
            return;
        }

        Promise.all([
            document.fonts.load('400 16px "SarabunLocal"'),
            document.fonts.load('600 16px "SarabunLocal"'),
            document.fonts.load('700 16px "SarabunLocal"'),
            document.fonts.load('800 16px "SarabunLocal"'),
            document.fonts.ready
        ]).then(function () {
            window.requestAnimationFrame(function () {
                window.print();
            });
        }).catch(function () {
            alert('ไม่สามารถโหลดฟอนต์ Sarabun ได้ กรุณาตรวจสอบโฟลเดอร์ assets/fonts/sarabun');
        });
    }

    fetch('log_print.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body: JSON.stringify(historyPayload)
    }).then(function (response) {
        return response.json().then(function (json) {
            if (!response.ok || json.success === false) {
                throw new Error(json.message || 'ไม่สามารถบันทึกประวัติการพิมพ์ได้');
            }
            return json;
        });
    }).then(function () {
        openBrowserPrint();
    }).catch(function (error) {
        console.error(error);
        if (confirm((error.message || 'ไม่สามารถบันทึกประวัติการพิมพ์ได้') + '\n\nต้องการพิมพ์ต่อโดยไม่บันทึกประวัติหรือไม่?')) {
            openBrowserPrint();
        }
    });
}

function setPrintOrientation(orientation) {
    var isLandscape = orientation === 'landscape';
    document.body.classList.toggle('landscape', isLandscape);
    document.body.classList.toggle('portrait', !isLandscape);

    var pageStyle = document.getElementById('pageOrientationStyle');
    if (pageStyle) {
        pageStyle.textContent = '@page{size:A4 ' + (isLandscape ? 'landscape' : 'portrait') + ';margin:8mm}';
    }

    var portraitBtn = document.getElementById('portraitBtn');
    var landscapeBtn = document.getElementById('landscapeBtn');
    if (portraitBtn) portraitBtn.classList.toggle('active', !isLandscape);
    if (landscapeBtn) landscapeBtn.classList.toggle('active', isLandscape);
}
</script></body></html>
