<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

if (function_exists('require_login')) {
    require_login();
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('เซิร์ฟเวอร์ไม่รองรับ ZipArchive ซึ่งจำเป็นสำหรับการสร้างไฟล์ Excel');
}

if (!function_exists('drumExcelXml')) {
    function drumExcelXml($value): string
    {
        $value = (string)($value ?? '');
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('drumExcelNormalizeMainCode')) {
    function drumExcelNormalizeMainCode($value): string
    {
        $value = preg_replace('/\D+/', '', trim((string)($value ?? '')));
        return $value === '' ? '' : str_pad(substr($value, 0, 3), 3, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('drumExcelColumnName')) {
    function drumExcelColumnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }
        return $name;
    }
}

if (!function_exists('drumExcelInlineCell')) {
    function drumExcelInlineCell(string $cell, $value, int $styleId = 0): string
    {
        return '<c r="' . $cell . '" t="inlineStr" s="' . $styleId . '"><is><t xml:space="preserve">' . drumExcelXml($value) . '</t></is></c>';
    }
}

if (!function_exists('drumExcelNumberCell')) {
    function drumExcelNumberCell(string $cell, $value, int $styleId = 0): string
    {
        return '<c r="' . $cell . '" s="' . $styleId . '"><v>' . (float)$value . '</v></c>';
    }
}

if (!function_exists('drumExcelCurrentUserName')) {
    function drumExcelCurrentUserName(): string
    {
        $name = trim((string)($_SESSION['full_name'] ?? ''));
        if ($name === '' && !empty($_SESSION['user']) && is_array($_SESSION['user'])) {
            $name = trim((string)($_SESSION['user']['full_name'] ?? ''));
        }
        if ($name === '') {
            $name = trim((string)($_SESSION['display_name'] ?? $_SESSION['username'] ?? ''));
        }
        return $name;
    }
}

if (!function_exists('drumExcelCurrentUserPosition')) {
    function drumExcelCurrentUserPosition(PDO $pdo): string
    {
        $position = trim((string)(
            $_SESSION['position']
            ?? $_SESSION['position_name']
            ?? $_SESSION['job_title']
            ?? $_SESSION['employee_position']
            ?? $_SESSION['title']
            ?? ''
        ));

        if ($position === '' && !empty($_SESSION['user']) && is_array($_SESSION['user'])) {
            foreach (['position', 'position_name', 'job_title', 'employee_position', 'title'] as $field) {
                $value = trim((string)($_SESSION['user'][$field] ?? ''));
                if ($value !== '') {
                    $position = $value;
                    break;
                }
            }
        }

        return $position;
    }
}

if (!function_exists('drumExcelFetchRows')) {
    function drumExcelFetchRows(PDO $pdo, string $keyword, string $drumFilter, string $dateFrom, string $dateTo, string $deliveryStatus = ''): array
    {
        $columnsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'harddisk_db' AND TABLE_NAME = 'drum_withdrawals'");
        $columnsStmt->execute();
        $columns = array_map('strtolower', $columnsStmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$columns) {
            throw new RuntimeException('ไม่พบตาราง drum_withdrawals');
        }

        $requiredColumns = ['request_no', 'main_branch_code', 'branch_name', 'drum_code', 'recorded_by', 'created_at'];
        $missingColumns = array_values(array_diff($requiredColumns, $columns));
        if ($missingColumns) {
            throw new RuntimeException('ตาราง drum_withdrawals ขาดคอลัมน์: ' . implode(', ', $missingColumns));
        }

        $hasBranchCode = in_array('branch_code', $columns, true);
        $hasQuantity = in_array('quantity', $columns, true);
        if (!$hasQuantity) throw new RuntimeException('ตาราง drum_withdrawals ยังไม่มีคอลัมน์ quantity กรุณารันไฟล์ database/add_drum_quantity.sql');
        $hasDeletedAt = in_array('deleted_at', $columns, true);
        $hasDeliveryStatus = in_array('delivery_status', $columns, true);
        $where = [];
        $params = [];

        if ($hasDeletedAt) {
            $where[] = 'dw.deleted_at IS NULL';
        }
        if ($deliveryStatus !== '') {
            if (!$hasDeliveryStatus) {
                throw new RuntimeException('กรุณารันไฟล์ database/add_drum_delivery_status.sql ก่อน Export');
            }
            $where[] = 'dw.delivery_status = :delivery_status';
            $params[':delivery_status'] = $deliveryStatus;
        }
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $parts = [
                'dw.request_no LIKE :kw_request',
                'dw.main_branch_code LIKE :kw_main',
                'dw.branch_name LIKE :kw_name',
                'dw.recorded_by LIKE :kw_recorded',
                'dw.drum_code LIKE :kw_drum',
            ];
            $params = [
                ':kw_request' => $like,
                ':kw_main' => $like,
                ':kw_name' => $like,
                ':kw_recorded' => $like,
                ':kw_drum' => $like,
            ];
            if ($hasBranchCode) {
                $parts[] = 'dw.branch_code LIKE :kw_branch';
                $params[':kw_branch'] = $like;
            } else {
                $parts[] = "EXISTS (
                    SELECT 1 FROM harddisk_db.branch_directory bd_kw
                    WHERE LPAD(TRIM(CAST(bd_kw.main_branch_code AS CHAR)), 3, '0') = LPAD(TRIM(CAST(dw.main_branch_code AS CHAR)), 3, '0')
                      AND (TRIM(bd_kw.branch_name) = TRIM(dw.branch_name) OR TRIM(COALESCE(bd_kw.branch_name_2, '')) = TRIM(dw.branch_name))
                      AND TRIM(CAST(bd_kw.branch_code AS CHAR)) LIKE :kw_branch_lookup
                )";
                $params[':kw_branch_lookup'] = $like;
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        if ($drumFilter !== '') {
            $where[] = 'dw.drum_code = :drum_code';
            $params[':drum_code'] = $drumFilter;
        }
        if ($dateFrom !== '') {
            $where[] = 'DATE(dw.created_at) >= :date_from';
            $params[':date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = 'DATE(dw.created_at) <= :date_to';
            $params[':date_to'] = $dateTo;
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $branchSelect = $hasBranchCode ? 'dw.branch_code' : "COALESCE((
            SELECT bd.branch_code
            FROM harddisk_db.branch_directory bd
            WHERE LPAD(TRIM(CAST(bd.main_branch_code AS CHAR)), 3, '0') = LPAD(TRIM(CAST(dw.main_branch_code AS CHAR)), 3, '0')
              AND (TRIM(bd.branch_name) = TRIM(dw.branch_name) OR TRIM(COALESCE(bd.branch_name_2, '')) = TRIM(dw.branch_name))
            ORDER BY bd.branch_code ASC LIMIT 1
        ), '')";
        $branchGroup = $hasBranchCode ? ', dw.branch_code' : '';

        $sql = "SELECT dw.request_no, dw.main_branch_code, {$branchSelect} AS branch_code, dw.branch_name,
                       GROUP_CONCAT(dw.drum_code ORDER BY dw.drum_code SEPARATOR ', ') AS drum_codes,
                       SUM(CASE WHEN dw.drum_code='Drum-DR-3455' THEN COALESCE(dw.quantity,1) ELSE 0 END) AS drum_3455_qty,
                       SUM(CASE WHEN dw.drum_code='Drum-DR-3608' THEN COALESCE(dw.quantity,1) ELSE 0 END) AS drum_3608_qty,
                       dw.recorded_by, MIN(dw.created_at) AS created_at
                FROM harddisk_db.drum_withdrawals dw
                {$whereSql}
                GROUP BY dw.request_no, dw.main_branch_code{$branchGroup}, dw.branch_name, dw.recorded_by
                ORDER BY MIN(dw.created_at) DESC
                LIMIT 5000";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('drumExcelResolveDirectory')) {
    function drumExcelResolveDirectory(PDO $pdo, array $rows): array
    {
        $mainCodes = [];
        foreach ($rows as $row) {
            $code = drumExcelNormalizeMainCode($row['main_branch_code'] ?? '');
            if ($code !== '') {
                $mainCodes[$code] = true;
            }
        }
        if (!$mainCodes) {
            return ['main_names' => [], 'branch_types' => []];
        }

        $params = [];
        $placeholders = [];
        foreach (array_keys($mainCodes) as $index => $code) {
            $key = ':code_' . $index;
            $placeholders[] = $key;
            $params[$key] = $code;
        }

        $stmt = $pdo->prepare("SELECT main_branch_code, branch_code, branch_name, branch_name_2, branch_type
            FROM harddisk_db.branch_directory
            WHERE LPAD(TRIM(CAST(main_branch_code AS CHAR)), 3, '0') IN (" . implode(', ', $placeholders) . ")
            ORDER BY main_branch_code, branch_code");
        $stmt->execute($params);
        $directoryRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $mainNames = [];
        $mainScores = [];
        foreach ($directoryRows as $directoryRow) {
            $mainCode = drumExcelNormalizeMainCode($directoryRow['main_branch_code'] ?? '');
            if ($mainCode === '' || trim((string)($directoryRow['branch_type'] ?? '')) !== 'สาขาใหญ่') {
                continue;
            }
            $name = trim((string)($directoryRow['branch_name'] ?? ''));
            if ($name === '') {
                $name = trim((string)($directoryRow['branch_name_2'] ?? ''));
            }
            if ($name === '') {
                continue;
            }
            $branchCode = trim((string)($directoryRow['branch_code'] ?? ''));
            $score = 1;
            if ($branchCode === $mainCode || ltrim($branchCode, '0') === ltrim($mainCode, '0')) $score += 100;
            if (preg_match('/(?:สาขา\s*ใหญ่|สำนักงานใหญ่)/u', $name)) $score += 80;
            if (!isset($mainScores[$mainCode]) || $score > $mainScores[$mainCode]) {
                $mainScores[$mainCode] = $score;
                $mainNames[$mainCode] = $name;
            }
        }

        $branchTypes = [];
        foreach ($rows as $row) {
            $requestNo = trim((string)($row['request_no'] ?? ''));
            $mainCode = drumExcelNormalizeMainCode($row['main_branch_code'] ?? '');
            $branchCode = trim((string)($row['branch_code'] ?? ''));
            $branchName = trim((string)($row['branch_name'] ?? ''));
            foreach ($directoryRows as $directoryRow) {
                if (drumExcelNormalizeMainCode($directoryRow['main_branch_code'] ?? '') !== $mainCode) continue;
                $directoryCode = trim((string)($directoryRow['branch_code'] ?? ''));
                $directoryName = trim((string)($directoryRow['branch_name'] ?? ''));
                $directoryName2 = trim((string)($directoryRow['branch_name_2'] ?? ''));
                $codeMatched = $branchCode !== '' && $directoryCode !== '' && strcasecmp($branchCode, $directoryCode) === 0;
                $nameMatched = $branchName !== '' && (strcasecmp($branchName, $directoryName) === 0 || strcasecmp($branchName, $directoryName2) === 0);
                if ($codeMatched || $nameMatched) {
                    $branchTypes[$requestNo] = trim((string)($directoryRow['branch_type'] ?? ''));
                    break;
                }
            }
        }

        return ['main_names' => $mainNames, 'branch_types' => $branchTypes];
    }
}

$keyword = trim((string)($_GET['keyword'] ?? ''));
$drumFilter = trim((string)($_GET['drum_code'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$deliveryStatus = trim((string)($_GET['delivery_status'] ?? ''));
if (!in_array($deliveryStatus, ['pending', 'shipped'], true)) $deliveryStatus = '';
$allowedDrumFilters = ['Drum-DR-3455', 'Drum-DR-3608'];
if (!in_array($drumFilter, $allowedDrumFilters, true)) {
    $drumFilter = '';
}

$tempFile = null;
try {
    $rawRows = drumExcelFetchRows($pdo, $keyword, $drumFilter, $dateFrom, $dateTo, $deliveryStatus);
    $directory = drumExcelResolveDirectory($pdo, $rawRows);
    $mainNames = $directory['main_names'];
    $branchTypes = $directory['branch_types'];

    $rows = [];
    foreach ($rawRows as $row) {
        $mainCode = drumExcelNormalizeMainCode($row['main_branch_code'] ?? '');
        $selectedBranchName = trim((string)($row['branch_name'] ?? ''));
        $mainBranchName = trim((string)($mainNames[$mainCode] ?? ''));
        if ($mainBranchName === '') {
            $mainBranchName = $selectedBranchName;
        }

        $requestNo = trim((string)($row['request_no'] ?? ''));
        $selectedBranchType = trim((string)($branchTypes[$requestNo] ?? ''));
        $subBranchName = '';
        if ($selectedBranchType === 'สาขาใหญ่') {
            $subBranchName = $selectedBranchName !== '' ? $selectedBranchName : $mainBranchName;
        } elseif ($selectedBranchName !== '' && strcasecmp($selectedBranchName, $mainBranchName) !== 0) {
            $subBranchName = $selectedBranchName;
        }

        $rows[] = [
            'branch_code' => $mainCode !== '' ? $mainCode : trim((string)($row['main_branch_code'] ?? '')),
            'main_branch' => $mainBranchName,
            'sub_branch' => $subBranchName,
            'cost_center' => trim((string)($row['branch_code'] ?? '')),
            'drum_3455' => (int)($row['drum_3455_qty'] ?? 0),
            'drum_3608' => (int)($row['drum_3608_qty'] ?? 0),
            'notified_by' => trim((string)($row['recorded_by'] ?? '')),
        ];
    }

    $requester = drumExcelCurrentUserName();
    $department = drumExcelCurrentUserPosition($pdo);
    $printedAt = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));

    $sheetRows = [];
    $sheetRows[] = '<row r="1" ht="28" customHeight="1">' . drumExcelInlineCell('A1', 'บริษัท เมืองไทย แคปปิตอล จำกัด (มหาชน)', 1) . '</row>';
    $sheetRows[] = '<row r="2" ht="26" customHeight="1">' . drumExcelInlineCell('A2', 'ใบเบิกทรัพย์สินสำนักงานใหญ่', 2) . '</row>';
    $sheetRows[] = '<row r="3" ht="22" customHeight="1">'
        . drumExcelInlineCell('A3', 'ชื่อผู้เบิก', 3)
        . drumExcelInlineCell('B3', $requester !== '' ? $requester : '-', 4)
        . drumExcelInlineCell('D3', 'ฝ่าย/สังกัด', 3)
        . drumExcelInlineCell('E3', $department !== '' ? $department : '-', 4)
        . drumExcelInlineCell('G3', 'วันที่', 3)
        . drumExcelInlineCell('H3', $printedAt->format('d/m/Y'), 5)
        . '</row>';

    $sheetRows[] = '<row r="5" ht="25" customHeight="1">'
        . drumExcelInlineCell('A5', 'รหัสสาขา', 6)
        . drumExcelInlineCell('B5', 'สาขาใหญ่', 6)
        . drumExcelInlineCell('C5', 'สาขาย่อย/ศูนย์บริการ', 6)
        . drumExcelInlineCell('D5', 'ศูนย์ต้นทุน', 6)
        . drumExcelInlineCell('E5', 'รายการเบิก', 6)
        . drumExcelInlineCell('G5', 'ผู้แจ้ง', 6)
        . '</row>';
    $sheetRows[] = '<row r="6" ht="34" customHeight="1">'
        . drumExcelInlineCell('E6', "Drum 56-59\nDR-3455", 7)
        . drumExcelInlineCell('F6', "Drum 5915\nDR-3608", 7)
        . '</row>';

    $excelRow = 7;
    $total3455 = 0;
    $total3608 = 0;
    foreach ($rows as $row) {
        $total3455 += $row['drum_3455'];
        $total3608 += $row['drum_3608'];
        $sheetRows[] = '<row r="' . $excelRow . '" ht="24" customHeight="1">'
            . drumExcelInlineCell('A' . $excelRow, $row['branch_code'], 8)
            . drumExcelInlineCell('B' . $excelRow, $row['main_branch'], 9)
            . drumExcelInlineCell('C' . $excelRow, $row['sub_branch'], 9)
            . drumExcelInlineCell('D' . $excelRow, $row['cost_center'], 8)
            . drumExcelNumberCell('E' . $excelRow, $row['drum_3455'], 10)
            . drumExcelNumberCell('F' . $excelRow, $row['drum_3608'], 10)
            . drumExcelInlineCell('G' . $excelRow, $row['notified_by'], 9)
            . '</row>';
        $excelRow++;
    }

    if (!$rows) {
        $sheetRows[] = '<row r="7" ht="28" customHeight="1">' . drumExcelInlineCell('A7', 'ไม่พบข้อมูลตามเงื่อนไขที่เลือก', 11) . '</row>';
        $excelRow = 8;
    }

    $totalRow = $excelRow;
    $sheetRows[] = '<row r="' . $totalRow . '" ht="26" customHeight="1">'
        . drumExcelInlineCell('A' . $totalRow, '', 12)
        . drumExcelInlineCell('D' . $totalRow, 'รวมทั้งหมด', 12)
        . drumExcelNumberCell('E' . $totalRow, $total3455, 12)
        . drumExcelNumberCell('F' . $totalRow, $total3608, 12)
        . drumExcelInlineCell('G' . $totalRow, '', 12)
        . '</row>';

    $noteRow = $totalRow + 2;
    $sheetRows[] = '<row r="' . $noteRow . '" ht="24" customHeight="1">' . drumExcelInlineCell('A' . $noteRow, 'หมายเหตุ : เซ็นรับของแล้วรบกวนสแกนให้ฝ่ายบัญชีสำนักงานใหญ่ด้วยครับ', 13) . '</row>';
    $signatureDate = $printedAt->format('d/m/Y');
    $sheetRows[] = '<row r="' . ($noteRow + 2) . '" ht="24" customHeight="1">'
        . drumExcelInlineCell('B' . ($noteRow + 2), 'ผู้ขอเบิก(ฝ่ายไอที) ________________________________', 14)
        . drumExcelInlineCell('E' . ($noteRow + 2), 'วันที่', 3)
        . drumExcelInlineCell('F' . ($noteRow + 2), $signatureDate, 5)
        . '</row>';
    $sheetRows[] = '<row r="' . ($noteRow + 4) . '" ht="24" customHeight="1">'
        . drumExcelInlineCell('B' . ($noteRow + 4), 'ผู้อนุมัติ(ฝ่ายไอที) _________________________________', 14)
        . drumExcelInlineCell('E' . ($noteRow + 4), 'วันที่', 3)
        . drumExcelInlineCell('F' . ($noteRow + 4), $signatureDate, 5)
        . '</row>';
    $sheetRows[] = '<row r="' . ($noteRow + 6) . '" ht="24" customHeight="1">'
        . drumExcelInlineCell('B' . ($noteRow + 6), 'ผู้ส่งมอบทรัพย์สิน(ฝ่ายบัญชี) _________________________', 14)
        . drumExcelInlineCell('E' . ($noteRow + 6), 'วันที่', 3)
        . drumExcelInlineCell('F' . ($noteRow + 6), $signatureDate, 5)
        . '</row>';
    $sheetRows[] = '<row r="' . ($noteRow + 8) . '" ht="18" customHeight="1">' . drumExcelInlineCell('F' . ($noteRow + 8), 'พิมพ์เอกสารเมื่อ ' . $printedAt->format('d/m/Y H:i:s') . ' น.', 15) . '</row>';

    $lastRow = $noteRow + 8;
    $mergeRefs = [
        'A1:G1', 'A2:G2', 'B3:C3', 'E3:F3',
        'A5:A6', 'B5:B6', 'C5:C6', 'D5:D6', 'E5:F5', 'G5:G6',
        'A' . $totalRow . ':C' . $totalRow,
        'A' . $noteRow . ':G' . $noteRow,
        'B' . ($noteRow + 2) . ':D' . ($noteRow + 2),
        'F' . ($noteRow + 2) . ':G' . ($noteRow + 2),
        'B' . ($noteRow + 4) . ':D' . ($noteRow + 4),
        'F' . ($noteRow + 4) . ':G' . ($noteRow + 4),
        'B' . ($noteRow + 6) . ':D' . ($noteRow + 6),
        'F' . ($noteRow + 6) . ':G' . ($noteRow + 6),
        'F' . ($noteRow + 8) . ':G' . ($noteRow + 8),
    ];
    if (!$rows) {
        $mergeRefs[] = 'A7:G7';
    }
    $mergeXml = '<mergeCells count="' . count($mergeRefs) . '">';
    foreach ($mergeRefs as $ref) {
        $mergeXml .= '<mergeCell ref="' . $ref . '"/>';
    }
    $mergeXml .= '</mergeCells>';

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="A1:G' . $lastRow . '"/>'
        . '<sheetViews><sheetView tabSelected="1" showGridLines="0" workbookViewId="0"><pane ySplit="6" topLeftCell="A7" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . '<cols>'
        . '<col min="1" max="1" width="13" customWidth="1"/>'
        . '<col min="2" max="2" width="28" customWidth="1"/>'
        . '<col min="3" max="3" width="28" customWidth="1"/>'
        . '<col min="4" max="4" width="17" customWidth="1"/>'
        . '<col min="5" max="6" width="17" customWidth="1"/>'
        . '<col min="7" max="7" width="24" customWidth="1"/>'
        . '</cols>'
        . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
        . '<autoFilter ref="A5:G' . max(6, $totalRow - 1) . '"/>'
        . $mergeXml
        . '<printOptions horizontalCentered="1" verticalCentered="0"/>'
        . '<pageMargins left="0.25" right="0.25" top="0.35" bottom="0.35" header="0.15" footer="0.15"/>'
        . '<pageSetup orientation="portrait" fitToWidth="1" fitToHeight="0" paperSize="9"/>'
        . '<headerFooter><oddFooter>&amp;Rหน้า &amp;P / &amp;N</oddFooter></headerFooter>'
        . '</worksheet>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="8">'
        . '<font><sz val="11"/><name val="Tahoma"/><family val="2"/></font>'
        . '<font><b/><sz val="16"/><name val="Tahoma"/><family val="2"/></font>'
        . '<font><b/><sz val="14"/><name val="Tahoma"/><family val="2"/></font>'
        . '<font><b/><sz val="10"/><name val="Tahoma"/><family val="2"/></font>'
        . '<font><b/><sz val="11"/><name val="Tahoma"/><family val="2"/></font>'
        . '<font><sz val="10"/><name val="Tahoma"/><family val="2"/></font>'
        . '<font><b/><sz val="10"/><color rgb="FF111111"/><name val="Tahoma"/><family val="2"/></font>'
        . '<font><sz val="9"/><color rgb="FF64748B"/><name val="Tahoma"/><family val="2"/></font>'
        . '</fonts>'
        . '<fills count="5">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFBFD7ED"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFC8C8C8"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="3">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FF111111"/></left><right style="thin"><color rgb="FF111111"/></right><top style="thin"><color rgb="FF111111"/></top><bottom style="thin"><color rgb="FF111111"/></bottom><diagonal/></border>'
        . '<border><bottom style="thin"><color rgb="FF111111"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="16">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="5" fillId="0" borderId="2" xfId="0"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="5" fillId="0" borderId="2" xfId="0"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="4" fillId="2" borderId="1" xfId="0"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="6" fillId="4" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="5" fillId="4" borderId="1" xfId="0"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="6" fillId="4" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="5" fillId="4" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="4" fillId="3" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="5" fillId="0" borderId="0" xfId="0"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="5" fillId="0" borderId="0" xfId="0"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="7" fillId="0" borderId="0" xfId="0"><alignment horizontal="right" vertical="center"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<workbookPr/><bookViews><workbookView activeTab="0"/></bookViews>'
        . '<sheets><sheet name="ใบเบิก Drum" sheetId="1" r:id="rId1"/></sheets>'
        . '<definedNames><definedName name="_xlnm.Print_Area" localSheetId="0">\'ใบเบิก Drum\'!$A$1:$G$' . $lastRow . '</definedName><definedName name="_xlnm.Print_Titles" localSheetId="0">\'ใบเบิก Drum\'!$5:$6</definedName></definedNames>'
        . '<calcPr calcId="171027" fullCalcOnLoad="1"/>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:creator>Harddisk Delivery Web</dc:creator><cp:lastModifiedBy>Harddisk Delivery Web</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
        . '<dc:title>ใบเบิกทรัพย์สินสำนักงานใหญ่</dc:title></cp:coreProperties>';

    $app = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>Microsoft Excel</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>'
        . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs>'
        . '<TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>ใบเบิก Drum</vt:lpstr></vt:vector></TitlesOfParts>'
        . '</Properties>';

    $tempFile = tempnam(sys_get_temp_dir(), 'drum_excel_');
    if ($tempFile === false) {
        throw new RuntimeException('ไม่สามารถสร้างไฟล์ Excel ชั่วคราวได้');
    }

    $zip = new ZipArchive();
    if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('ไม่สามารถสร้างไฟล์ Excel ได้');
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('docProps/core.xml', $core);
    $zip->addFromString('docProps/app.xml', $app);
    $zip->close();

    clearstatcache(true, $tempFile);
    if (!is_file($tempFile) || filesize($tempFile) <= 0) {
        throw new RuntimeException('ไฟล์ Excel ที่สร้างไม่สมบูรณ์');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $filename = 'drum_withdrawals_' . $printedAt->format('Y-m-d_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . filesize($tempFile));
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: public');
    header('Expires: 0');
    readfile($tempFile);
    @unlink($tempFile);
    exit;
} catch (Throwable $e) {
    if ($tempFile && is_file($tempFile)) {
        @unlink($tempFile);
    }
    error_log('[drum_withdrawals/export_excel] ' . $e->getMessage());
    http_response_code(500);
    echo 'ไม่สามารถ Export Excel ได้: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
