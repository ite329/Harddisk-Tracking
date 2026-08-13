<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../branch_labels/print_history_common.php';

if (function_exists('require_login')) {
    require_login();
}

if (empty($_SESSION['csrf_drum'])) {
    $_SESSION['csrf_drum'] = bin2hex(random_bytes(32));
}

if (!function_exists('coverE')) {
    function coverE($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('coverNormalizeMainCode')) {
    function coverNormalizeMainCode($value): string
    {
        $value = preg_replace('/\D+/', '', trim((string)($value ?? '')));
        return $value === '' ? '' : str_pad(substr($value, 0, 3), 3, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('coverQuoteColumn')) {
    function coverQuoteColumn(string $column): string
    {
        return '`' . str_replace('`', '``', $column) . '`';
    }
}


if (!function_exists('coverResolveHistoryAddress')) {
    function coverResolveHistoryAddress(PDO $pdo, string $mainBranchCode, string $branchCode, string $branchName): string
    {
        $columnsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'harddisk_db' AND TABLE_NAME = 'branch_directory'");
        $columnsStmt->execute();
        $columns = [];
        foreach ($columnsStmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
            $columns[strtolower((string)$column)] = (string)$column;
        }

        $pick = static function (array $candidates) use ($columns): string {
            foreach ($candidates as $candidate) {
                if (isset($columns[$candidate])) return $columns[$candidate];
            }
            return '';
        };

        $addressColumn = $pick(['full_address', 'address', 'branch_address', 'address_full', 'address_line']);
        $houseNoColumn = $pick(['house_no', 'address_no', 'no', 'number']);
        $mooColumn = $pick(['moo', 'village_no']);
        $soiColumn = $pick(['soi']);
        $roadColumn = $pick(['road']);
        $subdistrictColumn = $pick(['subdistrict', 'sub_district', 'tambon']);
        $districtColumn = $pick(['district', 'amphur', 'amphoe']);
        $provinceColumn = $pick(['province', 'changwat']);
        $postcodeColumn = $pick(['postcode', 'postal_code', 'zipcode', 'zip_code']);
        $branchName2Column = $pick(['branch_name_2', 'branch_name2', 'sub_branch_name']);

        $selectColumns = ['main_branch_code', 'branch_code', 'branch_name'];
        foreach ([$branchName2Column, $addressColumn, $houseNoColumn, $mooColumn, $soiColumn, $roadColumn, $subdistrictColumn, $districtColumn, $provinceColumn, $postcodeColumn] as $column) {
            if ($column !== '') $selectColumns[] = coverQuoteColumn($column);
        }
        $selectColumns = array_values(array_unique($selectColumns));

        $name2Sql = $branchName2Column !== '' ? 'TRIM(COALESCE(' . coverQuoteColumn($branchName2Column) . ", ''))" : "''";
        $sql = "SELECT " . implode(', ', $selectColumns) . "
                FROM harddisk_db.branch_directory
                WHERE LPAD(TRIM(CAST(main_branch_code AS CHAR)), 3, '0') = LPAD(TRIM(CAST(:main_branch_code AS CHAR)), 3, '0')
                  AND ((:branch_code_value <> '' AND TRIM(CAST(branch_code AS CHAR)) = :branch_code_match)
                       OR (:branch_name_value <> '' AND (TRIM(branch_name) = :branch_name_match OR {$name2Sql} = :branch_name_match_2)))
                ORDER BY CASE WHEN TRIM(CAST(branch_code AS CHAR)) = :branch_code_order THEN 0 ELSE 1 END, branch_code ASC
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':main_branch_code' => $mainBranchCode,
            ':branch_code_value' => $branchCode,
            ':branch_code_match' => $branchCode,
            ':branch_name_value' => $branchName,
            ':branch_name_match' => $branchName,
            ':branch_name_match_2' => $branchName,
            ':branch_code_order' => $branchCode,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$row) return '';

        $value = static function (array $row, string $column): string {
            return $column !== '' ? trim((string)($row[$column] ?? '')) : '';
        };
        $address = $value($row, $addressColumn);
        if ($address !== '') return $address;

        $parts = [];
        $houseNo = $value($row, $houseNoColumn); if ($houseNo !== '') $parts[] = $houseNo;
        $moo = $value($row, $mooColumn); if ($moo !== '') $parts[] = 'หมู่ ' . $moo;
        $soi = $value($row, $soiColumn); if ($soi !== '') $parts[] = 'ซอย ' . $soi;
        $road = $value($row, $roadColumn); if ($road !== '') $parts[] = 'ถนน ' . $road;
        $subdistrict = $value($row, $subdistrictColumn); if ($subdistrict !== '') $parts[] = 'ต.' . $subdistrict;
        $district = $value($row, $districtColumn); if ($district !== '') $parts[] = 'อ.' . $district;
        $province = $value($row, $provinceColumn); if ($province !== '') $parts[] = 'จ.' . $province;
        $postcode = $value($row, $postcodeColumn); if ($postcode !== '') $parts[] = $postcode;
        return implode(' ', $parts);
    }
}


if (!function_exists('coverMaskRecordedBy')) {
    function coverMaskRecordedBy($value): string
    {
        $name = trim((string)($value ?? ''));
        if ($name === '') {
            return '-';
        }

        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts || count($parts) < 2) {
            return $name;
        }

        $firstName = array_shift($parts);
        $maskedParts = array_map(static function (string $part): string {
            $length = function_exists('mb_strlen') ? mb_strlen($part, 'UTF-8') : strlen($part);
            return str_repeat('*', max(1, $length));
        }, $parts);

        return $firstName . ' ' . implode(' ', $maskedParts);
    }
}

if (($_POST['ajax'] ?? '') === 'confirm_print') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $postedRequestNo = trim((string)($_POST['request_no'] ?? ''));
        $orientation = trim((string)($_POST['print_orientation'] ?? 'portrait'));
        $csrfToken = (string)($_POST['csrf_token'] ?? '');
        if ($postedRequestNo === '') throw new RuntimeException('ไม่พบเลขที่รายการเบิก Drum');
        if (empty($_SESSION['csrf_drum']) || !hash_equals((string)$_SESSION['csrf_drum'], $csrfToken)) {
            throw new RuntimeException('Session หมดอายุ กรุณาเปิดใบปะหน้าใหม่');
        }
        if (!in_array($orientation, ['portrait', 'landscape'], true)) $orientation = 'portrait';

        branchLabelEnsurePrintHistoryTable($pdo);
        $columnStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'harddisk_db' AND TABLE_NAME = 'drum_withdrawals'");
        $columnStmt->execute();
        $columns = array_map('strtolower', $columnStmt->fetchAll(PDO::FETCH_COLUMN));
        $hasBranchCode = in_array('branch_code', $columns, true);
        $hasDeletedAt = in_array('deleted_at', $columns, true);
        $hasQuantity = in_array('quantity', $columns, true);
        if (!$hasQuantity) throw new RuntimeException('ตาราง drum_withdrawals ยังไม่มีคอลัมน์ quantity กรุณารันไฟล์ database/add_drum_quantity.sql');
        if (!in_array('delivery_status', $columns, true) || !in_array('shipped_at', $columns, true)) {
            throw new RuntimeException('กรุณารันไฟล์ database/add_drum_delivery_status.sql ก่อนใช้งาน');
        }

        $pdo->beginTransaction();
        $lockSql = "SELECT request_no, main_branch_code, " . ($hasBranchCode ? 'branch_code' : "'' AS branch_code") . ", branch_name, drum_code, COALESCE(quantity,1) AS quantity, COALESCE(delivery_status, 'pending') AS delivery_status
                    FROM harddisk_db.drum_withdrawals
                    WHERE request_no = :request_no" . ($hasDeletedAt ? ' AND deleted_at IS NULL' : '') . "
                    FOR UPDATE";
        $lockStmt = $pdo->prepare($lockSql);
        $lockStmt->execute([':request_no' => $postedRequestNo]);
        $lockedRows = $lockStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$lockedRows) throw new RuntimeException('ไม่พบข้อมูลรายการเบิก Drum');
        foreach ($lockedRows as $lockedRow) {
            if (($lockedRow['delivery_status'] ?? 'pending') !== 'pending') {
                throw new RuntimeException('รายการนี้พิมพ์ใบปะหน้าและยืนยันจัดส่งแล้ว ไม่สามารถพิมพ์ซ้ำได้');
            }
        }

        $firstRow = $lockedRows[0];
        $drumCodes = [];
        foreach ($lockedRows as $lockedRow) {
            $code = trim((string)($lockedRow['drum_code'] ?? ''));
            $qty = max(1, (int)($lockedRow['quantity'] ?? 1));
            if ($code !== '') $drumCodes[] = $code . ' x' . $qty;
        }
        sort($drumCodes, SORT_NATURAL);
        $mainBranchCode = trim((string)($firstRow['main_branch_code'] ?? ''));
        $branchCode = trim((string)($firstRow['branch_code'] ?? ''));
        $branchName = trim((string)($firstRow['branch_name'] ?? ''));
        $shippingAddress = coverResolveHistoryAddress($pdo, $mainBranchCode, $branchCode, $branchName);

        [$printedByName, $printedByEmployeeCode] = branchLabelCurrentPrintUser();
        $insertStmt = $pdo->prepare("INSERT INTO harddisk_db.branch_label_print_history
            (main_branch_code, branch_code, branch_name, shipping_address, asset_name, request_no, hdd_serial,
             print_orientation, print_source, printed_by_employee_code, printed_by_name, printed_ip, user_agent, printed_at)
            VALUES
            (:main_branch_code, :branch_code, :branch_name, :shipping_address, 'Drum', :request_no, :drum_codes,
             :print_orientation, 'drum_request', :employee_code, :printed_by_name, :printed_ip, :user_agent, NOW())");
        $insertStmt->execute([
            ':main_branch_code' => $mainBranchCode,
            ':branch_code' => $branchCode !== '' ? $branchCode : null,
            ':branch_name' => $branchName,
            ':shipping_address' => $shippingAddress !== '' ? $shippingAddress : null,
            ':request_no' => $postedRequestNo,
            ':drum_codes' => $drumCodes ? implode(', ', $drumCodes) : null,
            ':print_orientation' => $orientation,
            ':employee_code' => $printedByEmployeeCode !== '' ? $printedByEmployeeCode : null,
            ':printed_by_name' => $printedByName,
            ':printed_ip' => branchLabelClientIp(),
            ':user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);

        $updateSql = "UPDATE harddisk_db.drum_withdrawals
                      SET delivery_status = 'shipped', shipped_at = COALESCE(shipped_at, NOW())
                      WHERE request_no = :request_no AND COALESCE(delivery_status, 'pending') = 'pending'" . ($hasDeletedAt ? ' AND deleted_at IS NULL' : '');
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([':request_no' => $postedRequestNo]);
        if ($updateStmt->rowCount() !== count($lockedRows)) {
            throw new RuntimeException('สถานะรายการมีการเปลี่ยนแปลง กรุณารีเฟรชหน้าแล้วตรวจสอบอีกครั้ง');
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'ยืนยันการพิมพ์และเปลี่ยนสถานะเป็นจัดส่งแล้ว'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[drum_withdrawals/cover_sheet/confirm_print] ' . $e->getMessage());
        $status = str_contains($e->getMessage(), 'ไม่สามารถพิมพ์ซ้ำ') ? 409 : 400;
        http_response_code($status);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

$requestNo = trim((string)($_GET['request_no'] ?? ''));
if ($requestNo === '') {
    http_response_code(400);
    exit('ไม่พบเลขที่รายการ');
}

$coverData = null;
$errorMessage = '';

try {
    $columnsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'harddisk_db' AND TABLE_NAME = 'drum_withdrawals'");
    $columnsStmt->execute();
    $withdrawalColumns = array_map('strtolower', $columnsStmt->fetchAll(PDO::FETCH_COLUMN));
    $hasBranchCodeColumn = in_array('branch_code', $withdrawalColumns, true);
    $hasDeletedAtColumn = in_array('deleted_at', $withdrawalColumns, true);
    $hasQuantityColumn = in_array('quantity', $withdrawalColumns, true);
    if (!$hasQuantityColumn) throw new RuntimeException('ตาราง drum_withdrawals ยังไม่มีคอลัมน์ quantity กรุณารันไฟล์ database/add_drum_quantity.sql');

    $branchCodeSelect = $hasBranchCodeColumn ? 'dw.branch_code' : "''";
    $deletedCondition = $hasDeletedAtColumn ? ' AND dw.deleted_at IS NULL' : '';
    $stmt = $pdo->prepare("SELECT dw.request_no, dw.main_branch_code, {$branchCodeSelect} AS branch_code,
                                  dw.branch_name, GROUP_CONCAT(CONCAT(dw.drum_code, ' x', COALESCE(dw.quantity,1)) ORDER BY dw.drum_code SEPARATOR ', ') AS drum_codes,
                                  dw.recorded_by, MIN(dw.created_at) AS created_at,
                                  MIN(COALESCE(dw.delivery_status, 'pending')) AS delivery_status
                           FROM harddisk_db.drum_withdrawals dw
                           WHERE dw.request_no = :request_no{$deletedCondition}
                           GROUP BY dw.request_no, dw.main_branch_code, " . ($hasBranchCodeColumn ? 'dw.branch_code, ' : '') . "dw.branch_name, dw.recorded_by
                           LIMIT 1");
    $stmt->execute([':request_no' => $requestNo]);
    $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$withdrawal) {
        throw new RuntimeException('ไม่พบข้อมูลรายการเบิก Drum');
    }
    if (($withdrawal['delivery_status'] ?? 'pending') !== 'pending') {
        throw new RuntimeException('รายการนี้พิมพ์ใบปะหน้าและยืนยันจัดส่งแล้ว ไม่สามารถพิมพ์ซ้ำได้');
    }

    $directoryColumnsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'harddisk_db' AND TABLE_NAME = 'branch_directory'");
    $directoryColumnsStmt->execute();
    $directoryColumns = [];
    foreach ($directoryColumnsStmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
        $directoryColumns[strtolower((string)$column)] = (string)$column;
    }

    $pickColumn = static function (array $candidates) use ($directoryColumns): string {
        foreach ($candidates as $candidate) {
            if (isset($directoryColumns[$candidate])) {
                return $directoryColumns[$candidate];
            }
        }
        return '';
    };

    $addressColumn = $pickColumn(['address', 'branch_address', 'full_address', 'address_full']);
    $houseNoColumn = $pickColumn(['house_no', 'address_no', 'no', 'number']);
    $mooColumn = $pickColumn(['moo', 'village_no']);
    $soiColumn = $pickColumn(['soi']);
    $roadColumn = $pickColumn(['road']);
    $subdistrictColumn = $pickColumn(['subdistrict', 'sub_district', 'tambon']);
    $districtColumn = $pickColumn(['district', 'amphur', 'amphoe']);
    $provinceColumn = $pickColumn(['province', 'changwat']);
    $postcodeColumn = $pickColumn(['postcode', 'postal_code', 'zipcode', 'zip_code']);
    $phoneColumn = $pickColumn(['phone', 'telephone', 'tel', 'branch_phone']);

    $selectParts = ['main_branch_code', 'branch_code', 'branch_name', 'branch_name_2'];
    foreach ([$addressColumn, $houseNoColumn, $mooColumn, $soiColumn, $roadColumn, $subdistrictColumn, $districtColumn, $provinceColumn, $postcodeColumn, $phoneColumn] as $column) {
        if ($column !== '') {
            $selectParts[] = coverQuoteColumn($column);
        }
    }
    $selectParts = array_values(array_unique($selectParts));

    $mainCode = coverNormalizeMainCode($withdrawal['main_branch_code'] ?? '');
    $branchCode = trim((string)($withdrawal['branch_code'] ?? ''));
    $branchName = trim((string)($withdrawal['branch_name'] ?? ''));

    $directorySql = "SELECT " . implode(', ', $selectParts) . "
                     FROM harddisk_db.branch_directory
                     WHERE LPAD(TRIM(CAST(main_branch_code AS CHAR)), 3, '0') = :main_code
                       AND ((:branch_code <> '' AND TRIM(CAST(branch_code AS CHAR)) = :branch_code_match)
                            OR (:branch_name <> '' AND (TRIM(branch_name) = :branch_name_match OR TRIM(COALESCE(branch_name_2, '')) = :branch_name_match2)))
                     ORDER BY CASE WHEN TRIM(CAST(branch_code AS CHAR)) = :branch_code_order THEN 0 ELSE 1 END, branch_code ASC
                     LIMIT 1";
    $directoryStmt = $pdo->prepare($directorySql);
    $directoryStmt->execute([
        ':main_code' => $mainCode,
        ':branch_code' => $branchCode,
        ':branch_code_match' => $branchCode,
        ':branch_name' => $branchName,
        ':branch_name_match' => $branchName,
        ':branch_name_match2' => $branchName,
        ':branch_code_order' => $branchCode,
    ]);
    $directory = $directoryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $value = static function (array $row, string $column): string {
        return $column !== '' ? trim((string)($row[$column] ?? '')) : '';
    };

    $address = $value($directory, $addressColumn);
    if ($address === '') {
        $parts = [];
        $houseNo = $value($directory, $houseNoColumn);
        $moo = $value($directory, $mooColumn);
        $soi = $value($directory, $soiColumn);
        $road = $value($directory, $roadColumn);
        $subdistrict = $value($directory, $subdistrictColumn);
        $district = $value($directory, $districtColumn);
        $province = $value($directory, $provinceColumn);
        $postcode = $value($directory, $postcodeColumn);
        if ($houseNo !== '') $parts[] = $houseNo;
        if ($moo !== '') $parts[] = 'หมู่ ' . $moo;
        if ($soi !== '') $parts[] = 'ซอย ' . $soi;
        if ($road !== '') $parts[] = 'ถนน ' . $road;
        if ($subdistrict !== '') $parts[] = 'ต.' . $subdistrict;
        if ($district !== '') $parts[] = 'อ.' . $district;
        if ($province !== '') $parts[] = 'จ.' . $province;
        if ($postcode !== '') $parts[] = $postcode;
        $address = implode(' ', $parts);
    }

    $resolvedBranchName = trim((string)($directory['branch_name'] ?? ''));
    if ($resolvedBranchName === '') {
        $resolvedBranchName = trim((string)($directory['branch_name_2'] ?? ''));
    }
    if ($resolvedBranchName === '') {
        $resolvedBranchName = $branchName;
    }

    $mainBranchName = '';
    try {
        $mainBranchStmt = $pdo->prepare("SELECT branch_name, branch_name_2
            FROM harddisk_db.branch_directory
            WHERE LPAD(TRIM(CAST(main_branch_code AS CHAR)), 3, '0') = :main_code
              AND TRIM(COALESCE(branch_type, '')) = 'สาขาใหญ่'
            ORDER BY CASE WHEN TRIM(CAST(branch_code AS CHAR)) = :main_code_match THEN 0 ELSE 1 END, branch_code ASC
            LIMIT 1");
        $mainBranchStmt->execute([
            ':main_code' => $mainCode,
            ':main_code_match' => $mainCode,
        ]);
        $mainBranchRow = $mainBranchStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $mainBranchName = trim((string)($mainBranchRow['branch_name'] ?? ''));
        if ($mainBranchName === '') {
            $mainBranchName = trim((string)($mainBranchRow['branch_name_2'] ?? ''));
        }
    } catch (Throwable $mainBranchError) {
        error_log('[drum_withdrawals/cover_sheet] Cannot resolve main branch name: ' . $mainBranchError->getMessage());
    }

    $coverData = [
        'request_no' => $withdrawal['request_no'],
        'main_branch_code' => $mainCode !== '' ? $mainCode : $withdrawal['main_branch_code'],
        'branch_code' => $branchCode,
        'branch_name' => $resolvedBranchName,
        'main_branch_name' => $mainBranchName,
        'address' => $address !== '' ? $address : '-',
        'phone' => $value($directory, $phoneColumn),
        'drum_codes' => $withdrawal['drum_codes'],
        'recorded_by' => $withdrawal['recorded_by'],
        'created_at' => $withdrawal['created_at'],
    ];
} catch (Throwable $e) {
    error_log('[drum_withdrawals/cover_sheet] ' . $e->getMessage());
    $errorMessage = $e instanceof RuntimeException ? $e->getMessage() : 'ไม่สามารถสร้างใบปะหน้าได้';
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ใบปะหน้าจัดส่ง Drum - <?php echo coverE($requestNo); ?></title>
<link rel="preload" href="<?php echo coverE(($baseUrl ?? '/harddisk_delivery_web') . '/assets/fonts/sarabun/Sarabun-Regular.ttf?v=2'); ?>" as="font" type="font/ttf" crossorigin>
<link rel="preload" href="<?php echo coverE(($baseUrl ?? '/harddisk_delivery_web') . '/assets/fonts/sarabun/Sarabun-Bold.ttf?v=2'); ?>" as="font" type="font/ttf" crossorigin>
<style id="pageOrientationStyle">@page{size:A4 portrait;margin:8mm}</style>
<style>
@font-face{font-family:"SarabunLocal";src:url("<?php echo coverE(($baseUrl ?? '/harddisk_delivery_web') . '/assets/fonts/sarabun/Sarabun-Regular.ttf?v=2'); ?>") format("truetype");font-style:normal;font-weight:400;font-display:block}
@font-face{font-family:"SarabunLocal";src:url("<?php echo coverE(($baseUrl ?? '/harddisk_delivery_web') . '/assets/fonts/sarabun/Sarabun-SemiBold.ttf?v=2'); ?>") format("truetype");font-style:normal;font-weight:600;font-display:block}
@font-face{font-family:"SarabunLocal";src:url("<?php echo coverE(($baseUrl ?? '/harddisk_delivery_web') . '/assets/fonts/sarabun/Sarabun-Bold.ttf?v=2'); ?>") format("truetype");font-style:normal;font-weight:700;font-display:block}
@font-face{font-family:"SarabunLocal";src:url("<?php echo coverE(($baseUrl ?? '/harddisk_delivery_web') . '/assets/fonts/sarabun/Sarabun-ExtraBold.ttf?v=2'); ?>") format("truetype");font-style:normal;font-weight:800;font-display:block}
*{box-sizing:border-box}
:root{--blue:#0f4c81;--blue2:#1769aa;--ink:#0f172a;--muted:#64748b;--line:#cbd5e1;--soft:#f8fafc;--accent:#00acc1}
html,body,body *,button{font-family:"SarabunLocal",sans-serif!important}
body{margin:0;background:#e9eef5;color:var(--ink)}
.toolbar{position:sticky;top:0;z-index:10;display:flex;justify-content:center;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 16px;background:linear-gradient(135deg,var(--blue),var(--blue2));box-shadow:0 6px 20px rgba(15,76,129,.25)}
.toolbar button{border:0;border-radius:9px;padding:9px 17px;font-weight:800;cursor:pointer;font-size:14px;transition:.18s ease}.toolbar button:hover{transform:translateY(-1px)}
.print-btn{background:#fff;color:var(--blue)}.close-btn{background:#dbe5ee;color:#334155}
.orientation-group{display:inline-flex;gap:5px;padding:4px;border-radius:10px;background:rgba(255,255,255,.14)}
.orientation-btn{background:transparent!important;color:#fff;border:1px solid rgba(255,255,255,.45)!important;padding:7px 13px!important}.orientation-btn.active{background:#fff!important;color:var(--blue)!important;border-color:#fff!important}
.sheet{width:210mm;min-height:297mm;margin:18px auto;background:#fff;padding:18mm 12mm;display:flex;justify-content:center;align-items:flex-start;box-shadow:0 10px 35px rgba(15,23,42,.18)}
.parcel-label{position:relative;width:186mm;min-height:125mm;border:2.4px solid #0f172a;border-radius:4mm;overflow:hidden;background:#fff;box-shadow:inset 0 0 0 1px #fff}
.label-header{display:flex;align-items:stretch;justify-content:space-between;background:linear-gradient(135deg,#0f4c81,#1769aa);color:#fff;border-bottom:2px solid #0f172a}
.label-header-main{padding:4mm 5mm;min-width:0}.label-eyebrow{font-size:8pt;font-weight:800;letter-spacing:.5px;opacity:.88}.label-title{font-size:17pt;font-weight:900;line-height:1.1;margin-top:1mm}
.label-header-code{display:flex;align-items:center;justify-content:center;min-width:43mm;padding:3mm;border-left:1px solid rgba(255,255,255,.3);text-align:center}.label-header-code strong{display:block;font-size:16pt;line-height:1.1}.label-header-code span{display:block;font-size:7.5pt;opacity:.85;margin-top:1mm}
.label-body{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(54mm,.75fr);min-height:95mm}.label-main{padding:5mm 6mm;border-right:1.5px solid #0f172a}.label-side{padding:5mm;background:#f8fafc;display:flex;flex-direction:column;gap:3mm}
.section-label{display:flex;align-items:center;gap:2mm;font-size:8.5pt;font-weight:900;color:var(--blue);text-transform:uppercase;letter-spacing:.25px;margin-bottom:1.5mm}.section-label::before{content:"";width:3mm;height:3mm;border-radius:50%;background:var(--accent);flex:0 0 3mm}
.sender-box{padding:3mm 3.5mm;border:1px solid var(--line);border-radius:2.5mm;background:#f8fafc;font-size:8.7pt;line-height:1.45}
.recipient-block{margin-top:3mm;padding-top:3mm;border-top:1.5px dashed #94a3b8}.recipient-name{font-size:16pt;font-weight:900;line-height:1.25;color:#0f172a;margin-bottom:2mm}
.code-row{display:flex;gap:2mm;flex-wrap:wrap;margin-bottom:2.5mm}.code-pill{display:inline-flex;align-items:center;gap:1.5mm;border:1.5px solid #0f4c81;border-radius:2mm;padding:1.5mm 2.5mm;background:#eff6ff;color:#0f4c81;font-size:9pt;font-weight:900}
.address-box{font-size:10pt;line-height:1.5;color:#111827}.address-line{margin-top:1.3mm}.address-line strong{color:#334155}
.asset-card{border:1px solid #cbd5e1;border-radius:3mm;background:#fff;overflow:hidden}.asset-card-title{padding:2.2mm 3mm;background:#eaf3fb;color:#0f4c81;font-size:8pt;font-weight:900;border-bottom:1px solid #cbd5e1}.asset-card-name{padding:3mm;font-size:10pt;font-weight:900;text-align:center;line-height:1.45;white-space:normal;overflow-wrap:anywhere}
.detail-card{border:1px solid #cbd5e1;border-radius:3mm;background:#fff;padding:3mm;font-size:8pt;line-height:1.55}.detail-row{display:flex;justify-content:space-between;gap:3mm;border-bottom:1px dashed #cbd5e1;padding:1mm 0}.detail-row:last-child{border-bottom:0}.detail-row strong{color:#334155}
.notice-card{border:1.5px solid #f59e0b;border-radius:3mm;background:#fffbeb;padding:3mm;text-align:center;color:#92400e}.notice-card strong{display:block;font-size:10pt}.notice-card span{display:block;font-size:7.5pt;margin-top:1mm;line-height:1.35}
.logo-row{margin-top:auto;display:grid;grid-template-columns:1fr 1fr;gap:3mm;align-items:center}.logo-box{height:20mm;border:1px solid #dbe5ee;border-radius:2.5mm;background:#fff;display:flex;align-items:center;justify-content:center;padding:2mm}.label-fragile-image,.label-courier-image{max-width:100%;max-height:100%;object-fit:contain}
.label-footer{display:flex;justify-content:space-between;align-items:center;gap:4mm;padding:2.5mm 5mm;border-top:1.5px solid #0f172a;background:#fff;font-size:7.5pt;color:#475569}.label-footer strong{color:#0f172a}
.error{width:min(760px,calc(100% - 24px));margin:40px auto;background:#fff3f3;border:1px solid #fecaca;padding:18px;border-radius:12px;color:#991b1b;font-weight:700}
body.landscape .sheet{width:297mm;min-height:210mm;padding:8mm;align-items:stretch}
body.landscape .parcel-label{width:281mm;min-height:194mm}
body.landscape .label-header-main{padding:5mm 7mm}
body.landscape .label-eyebrow{font-size:10pt}
body.landscape .label-title{font-size:22pt}
body.landscape .label-header-code{min-width:50mm;padding:4mm}
body.landscape .label-header-code strong{font-size:22pt}
body.landscape .label-header-code span{font-size:9pt}
body.landscape .label-body{grid-template-columns:minmax(0,1.8fr) minmax(72mm,.65fr);min-height:158mm}
body.landscape .label-main{padding:7mm 8mm}
body.landscape .label-side{padding:6mm;gap:4mm}
body.landscape .section-label{font-size:10.5pt;margin-bottom:2mm}
body.landscape .sender-box{padding:4mm 4.5mm;font-size:11pt;line-height:1.55}
body.landscape .recipient-block{margin-top:4mm;padding-top:4mm}
body.landscape .recipient-name{font-size:22pt;margin-bottom:3mm}
body.landscape .code-pill{font-size:11pt;padding:2mm 3mm}
body.landscape .address-box{font-size:13pt;line-height:1.65}
body.landscape .asset-card-title{font-size:10pt;padding:3mm 4mm}
body.landscape .asset-card-name{font-size:13pt;padding:4mm}
body.landscape .detail-card{font-size:10pt;padding:4mm;line-height:1.65}
body.landscape .detail-row{padding:1.5mm 0}
body.landscape .notice-card{padding:4mm}
body.landscape .notice-card strong{font-size:13pt}
body.landscape .notice-card span{font-size:9pt}
body.landscape .logo-box{height:28mm}
body.landscape .label-footer{font-size:9pt;padding:3mm 6mm}
.drum-alert-popup{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(15,23,42,.55);backdrop-filter:blur(2px)}.drum-alert-popup-card{width:min(460px,100%);border-radius:18px;background:#fff;box-shadow:0 24px 70px rgba(15,23,42,.32);overflow:hidden}.drum-alert-popup-head{padding:18px 20px;background:linear-gradient(135deg,#fff7ed,#fff);border-bottom:1px solid #fed7aa;text-align:center}.drum-alert-popup-icon{width:52px;height:52px;margin:0 auto 10px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#ffedd5;color:#c2410c;font-size:28px;font-weight:900}.drum-alert-popup-title{margin:0;color:#9a3412;font-size:18px;font-weight:900}.drum-alert-popup-body{padding:20px;text-align:center;color:#334155;font-size:15px;line-height:1.6}.drum-alert-popup-actions{display:flex;justify-content:center;gap:8px;padding:0 20px 20px}.drum-alert-popup-btn{min-width:120px;border:0;border-radius:10px;padding:10px 18px;background:#0f4c81;color:#fff;font-weight:800;cursor:pointer}.drum-alert-popup-btn:hover{background:#0b3c68}
@media(max-width:900px){.sheet{margin:0;transform-origin:top left}.toolbar{position:relative}.parcel-label{max-width:100%}}
@media print{
body{background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.toolbar{display:none!important}
.sheet{width:100%;min-height:0;margin:0;padding:4mm 0;box-shadow:none;display:block}
.parcel-label{margin:0 auto;break-inside:avoid;page-break-inside:avoid}
body.landscape .sheet{width:281mm;min-height:194mm;padding:0;margin:0 auto;display:block}
body.landscape .parcel-label{width:281mm;min-height:194mm;margin:0;border-radius:3mm}
}
</style>
</head>
<body class="portrait">
<?php if ($errorMessage !== ''): ?>
<?php if ($errorMessage === 'รายการนี้พิมพ์ใบปะหน้าและยืนยันจัดส่งแล้ว ไม่สามารถพิมพ์ซ้ำได้'): ?>
<div class="drum-alert-popup" role="dialog" aria-modal="true" aria-labelledby="drumAlertPopupTitle">
    <div class="drum-alert-popup-card">
        <div class="drum-alert-popup-head">
            <div class="drum-alert-popup-icon">!</div>
            <h1 class="drum-alert-popup-title" id="drumAlertPopupTitle">ไม่สามารถพิมพ์ใบปะหน้าซ้ำได้</h1>
        </div>
        <div class="drum-alert-popup-body"><?php echo coverE($errorMessage); ?></div>
        <div class="drum-alert-popup-actions">
            <button type="button" class="drum-alert-popup-btn" onclick="closeDrumPopupPage()">ตกลง</button>
        </div>
    </div>
</div>
<script>
function closeDrumPopupPage(){
    if(window.opener&&!window.opener.closed){window.close();return;}
    if(window.history.length>1){window.history.back();return;}
    window.location.href='index.php';
}
</script>
<?php else: ?>
<div class="error"><?php echo coverE($errorMessage); ?></div>
<?php endif; ?>
<?php else: ?>
<div class="toolbar">
    <div class="orientation-group" aria-label="เลือกแนวกระดาษ">
        <button type="button" id="portraitBtn" class="orientation-btn active" onclick="setPrintOrientation('portrait')">แนวตั้ง</button>
        <button type="button" id="landscapeBtn" class="orientation-btn" onclick="setPrintOrientation('landscape')">แนวนอน</button>
    </div>
    <button class="print-btn" type="button" onclick="printLabel()">พิมพ์ใบปะหน้า</button>
    <button class="close-btn" type="button" onclick="window.close()">ปิดหน้านี้</button>
</div>
<div class="sheet">
    <div class="parcel-label">
        <div class="label-header">
            <div class="label-header-main">
                <div class="label-eyebrow">MUANGTHAI CAPITAL PUBLIC COMPANY LIMITED</div>
                <div class="label-title">ใบปะหน้าพัสดุ / จัดส่ง Drum</div>
            </div>
            <div class="label-header-code">
                <div><strong><?php echo coverE($coverData['main_branch_code']); ?></strong><span>รหัสสาขาใหญ่</span></div>
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
                    <div class="recipient-name">ถึง : <?php echo coverE($coverData['branch_name']); ?></div>
                    <div class="code-row">
                        <span class="code-pill">สาขาใหญ่ : <?php echo coverE($coverData['main_branch_name'] !== '' ? $coverData['main_branch_name'] : $coverData['main_branch_code']); ?></span>
                        <span class="code-pill">Cost Center : <?php echo coverE($coverData['branch_code'] ?: '-'); ?></span>
                    </div>
                    <div class="address-box">
                        <div class="address-line"><strong>ที่อยู่ :</strong> <?php echo nl2br(coverE($coverData['address'])); ?></div>
                        <?php if ($coverData['phone'] !== ''): ?><div class="address-line"><strong>โทร :</strong> <?php echo coverE($coverData['phone']); ?></div><?php endif; ?>
                    </div>
                </div>
            </div>
            <aside class="label-side">
                <div class="asset-card">
                    <div class="asset-card-title">รายการจัดส่ง</div>
                    <div class="asset-card-name"><?php echo coverE($coverData['drum_codes']); ?></div>
                </div>
                <div class="detail-card">
                    <div class="detail-row"><strong>เลขที่รายการ</strong><span><?php echo coverE($coverData['request_no']); ?></span></div>
                    <div class="detail-row"><strong>วันที่บันทึก</strong><span><?php echo coverE(date('d/m/Y', strtotime((string)$coverData['created_at']))); ?></span></div>
                    <div class="detail-row"><strong>ผู้บันทึก</strong><span><?php echo coverE(coverMaskRecordedBy($coverData['recorded_by'])); ?></span></div>
                </div>
                <div class="notice-card"><strong>โปรดระวังสินค้าแตกหัก</strong><span>กรุณาจัดวางและขนส่งด้วยความระมัดระวัง</span></div>
                <div class="logo-row">
                    <div class="logo-box"><img class="label-fragile-image" src="<?php echo coverE(($baseUrl ?? '/harddisk_delivery_web') . '/images/FRAGILE.jpg'); ?>" alt="Fragile"></div>
                    <div class="logo-box"><img class="label-courier-image" src="<?php echo coverE(($baseUrl ?? '/harddisk_delivery_web') . '/images/Kerry-Express-Logo.png'); ?>" alt="Kerry Express"></div>
                </div>
            </aside>
        </div>
        <div class="label-footer">
            <span><strong>เอกสารภายในองค์กร</strong> กรุณาตรวจสอบชื่อสาขาและ Cost Center ก่อนจัดส่ง</span>
            <span>พิมพ์เมื่อ <?php echo coverE(date('d/m/Y H:i')); ?> น.</span>
        </div>
    </div>
</div>
<div class="drum-alert-popup" id="drumRuntimePopup" role="dialog" aria-modal="true" aria-labelledby="drumRuntimePopupTitle" style="display:none">
    <div class="drum-alert-popup-card">
        <div class="drum-alert-popup-head">
            <div class="drum-alert-popup-icon">!</div>
            <h2 class="drum-alert-popup-title" id="drumRuntimePopupTitle">แจ้งเตือน</h2>
        </div>
        <div class="drum-alert-popup-body" id="drumRuntimePopupMessage">-</div>
        <div class="drum-alert-popup-actions">
            <button type="button" class="drum-alert-popup-btn" onclick="hideDrumRuntimePopup()">ตกลง</button>
        </div>
    </div>
</div>
<script>
function showDrumRuntimePopup(message,title){
    var popup=document.getElementById('drumRuntimePopup');
    var messageBox=document.getElementById('drumRuntimePopupMessage');
    var titleBox=document.getElementById('drumRuntimePopupTitle');
    if(messageBox)messageBox.textContent=message||'เกิดข้อผิดพลาด';
    if(titleBox)titleBox.textContent=title||'แจ้งเตือน';
    if(popup)popup.style.display='flex';
}
function hideDrumRuntimePopup(){var popup=document.getElementById('drumRuntimePopup');if(popup)popup.style.display='none';}
var currentPrintOrientation='portrait';
var drumPrintConfirmed=false;
async function confirmDrumPrint(){
    var formData=new URLSearchParams();
    formData.set('ajax','confirm_print');
    formData.set('request_no',<?php echo json_encode($requestNo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>);
    formData.set('print_orientation',currentPrintOrientation);
    formData.set('csrf_token',<?php echo json_encode($_SESSION['csrf_drum'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>);
    var response=await fetch('cover_sheet.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},body:formData.toString()});
    var result=await response.json().catch(function(){return {success:false,message:'ไม่สามารถอ่านผลการบันทึกประวัติการพิมพ์ได้'};});
    if(!response.ok||!result.success){throw new Error(result.message||'ไม่สามารถบันทึกประวัติการพิมพ์ได้');}
}
async function printLabel(){
    var printButton=document.querySelector('.print-btn');
    if(printButton){printButton.disabled=true;printButton.textContent='กำลังยืนยันจัดส่ง...';}
    try{
        await confirmDrumPrint();
        drumPrintConfirmed=true;
        try{
            if(window.opener&&!window.opener.closed){window.opener.postMessage({type:'drum-shipped',requestNo:<?php echo json_encode($requestNo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>},window.location.origin);}
            localStorage.setItem('drum_shipped_event',JSON.stringify({requestNo:<?php echo json_encode($requestNo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,time:Date.now()}));
        }catch(ignore){}
        if(document.fonts){
            await Promise.all([
                document.fonts.load('400 16px "SarabunLocal"'),
                document.fonts.load('600 16px "SarabunLocal"'),
                document.fonts.load('700 16px "SarabunLocal"'),
                document.fonts.load('800 16px "SarabunLocal"'),
                document.fonts.ready
            ]);
        }
        window.requestAnimationFrame(function(){window.print();});
    }catch(error){
        showDrumRuntimePopup(error&&error.message?error.message:'ไม่สามารถยืนยันการจัดส่งได้',error&&error.message&&error.message.indexOf('ไม่สามารถพิมพ์ซ้ำได้')!==-1?'ไม่สามารถพิมพ์ใบปะหน้าซ้ำได้':'ไม่สามารถยืนยันการจัดส่งได้');
    }finally{
        if(printButton){
            printButton.disabled=drumPrintConfirmed;
            printButton.textContent=drumPrintConfirmed?'ยืนยันจัดส่งแล้ว':'พิมพ์ใบปะหน้า';
        }
    }
}
function setPrintOrientation(orientation){
    currentPrintOrientation=orientation==='landscape'?'landscape':'portrait';
    var isLandscape=currentPrintOrientation==='landscape';
    document.body.classList.toggle('landscape',isLandscape);
    document.body.classList.toggle('portrait',!isLandscape);
    var pageStyle=document.getElementById('pageOrientationStyle');
    if(pageStyle){pageStyle.textContent='@page{size:A4 '+(isLandscape?'landscape':'portrait')+';margin:8mm}';}
    var portraitBtn=document.getElementById('portraitBtn');
    var landscapeBtn=document.getElementById('landscapeBtn');
    if(portraitBtn)portraitBtn.classList.toggle('active',!isLandscape);
    if(landscapeBtn)landscapeBtn.classList.toggle('active',isLandscape);
}
</script>
<?php endif; ?>
</body>
</html>
